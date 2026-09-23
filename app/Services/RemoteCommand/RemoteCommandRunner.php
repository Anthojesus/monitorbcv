<?php

namespace App\Services\RemoteCommand;

use App\Models\MonitorCommand;
use App\Models\MonitorCommandRun;
use App\Models\MonitorTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RemoteCommandRunner
{
    public function __construct(private readonly SshClient $ssh) {}

    public function run(User $actor, MonitorTarget $target, int $commandId, string $confirmation = ''): MonitorCommandRun
    {
        if (! $actor->canRunCommands()) {
            throw new RemoteCommandException('No tiene permiso para ejecutar comandos. Pídaselo a un administrador.');
        }

        $command = $target->commands()
            ->where('monitor_commands.id', $commandId)
            ->where('is_enabled', true)
            ->first();

        if ($command === null) {
            throw new RemoteCommandException('El comando no está habilitado para este sitio.');
        }

        if (! self::commandIsSafe($command->command)) {
            throw new RemoteCommandException('El comando almacenado no es válido.');
        }

        $server = $target->server;

        if ($server === null || ! $server->isReady()) {
            throw new RemoteCommandException('Este sitio no tiene host ni credenciales SSH configuradas.');
        }

        $password = $server->decryptedPassword();

        if ($password === '') {
            throw new RemoteCommandException('No se pudo descifrar la clave SSH. Vuelva a guardarla en el sitio.');
        }

        if ($command->isChange() && $confirmation !== $target->name) {
            throw new RemoteCommandException(
                'Escriba el nombre exacto del sitio para confirmar un comando de cambio.',
            );
        }

        $lock = Cache::lock('monitor:ssh:'.$target->id, $command->timeout_seconds + 5);

        if (! $lock->get()) {
            throw new RemoteCommandException('Ya hay un comando en ejecución en este servidor.');
        }

        $run = MonitorCommandRun::query()->create([
            'monitor_target_id' => $target->id,
            'monitor_command_id' => $command->id,
            'user_id' => $actor->id,
            'kind' => $command->kind,
            'command_label' => $command->label,
            'command_snapshot' => $command->command,
            'host' => $server->host,
            'username' => $server->username,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $started = microtime(true);

        try {
            $result = $this->ssh->exec(
                new SshConnection(
                    host: $server->host,
                    port: $server->port,
                    username: $server->username,
                    password: $password,
                    hostFingerprint: $server->host_fingerprint,
                ),
                $command->command,
                $command->timeout_seconds,
            );

            if ($server->host_fingerprint === null) {
                $server->forceFill(['host_fingerprint' => $result->hostFingerprint])->save();
            }

            $run->fill([
                'status' => $result->exitCode === 0 ? 'ok' : 'failed',
                'exit_code' => $result->exitCode,
                'stdout' => $this->truncate($result->stdout),
                'stderr' => $this->truncate($result->stderr),
                'duration_ms' => $this->elapsedMs($started),
                'finished_at' => now(),
            ])->save();
        } catch (RemoteCommandException $exception) {
            $run->fill([
                'status' => $exception->status,
                'error_message' => $exception->getMessage(),
                'duration_ms' => $this->elapsedMs($started),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        } catch (Throwable) {
            $run->fill([
                'status' => 'failed',
                'error_message' => 'Falló la ejecución remota.',
                'duration_ms' => $this->elapsedMs($started),
                'finished_at' => now(),
            ])->save();

            throw new RemoteCommandException('Falló la ejecución remota.');
        } finally {
            $lock->release();
        }

        return $run->fresh() ?? $run;
    }

    public static function commandIsSafe(string $command): bool
    {
        return $command !== '' && preg_match('/^[a-zA-Z0-9\/_.:= -]+$/', $command) === 1;
    }

    public function addToTarget(MonitorTarget $target, string $label, string $command, string $kind, int $timeoutSeconds = 15): MonitorCommand
    {
        $label = trim($label);
        $command = trim($command);

        if ($label === '') {
            throw new RemoteCommandException('Indique un nombre para reconocer el comando en la lista.');
        }

        if (! in_array($kind, ['query', 'change'], true)) {
            throw new RemoteCommandException('El tipo debe ser consulta o cambio.');
        }

        if (! self::commandIsSafe($command)) {
            throw new RemoteCommandException(
                'El comando o alias no es válido. Use solo letras, números, espacios, - _ / . : =. Sin ; | & $ ` ni paréntesis.',
            );
        }

        $existing = MonitorCommand::query()
            ->where('owner_target_id', $target->id)
            ->where('command', $command)
            ->first()
            ?? $target->commands()->where('monitor_commands.command', $command)->first();

        if ($existing !== null) {
            $target->commands()->syncWithoutDetaching([$existing->id]);

            return $existing;
        }

        $created = MonitorCommand::query()->create([
            'slug' => 'custom-'.$target->id.'-'.substr(hash('sha256', strtolower($command).'|'.$target->id), 0, 16),
            'label' => $label,
            'command' => $command,
            'kind' => $kind,
            'timeout_seconds' => $timeoutSeconds,
            'sort_order' => 500 + $target->commands()->count(),
            'is_enabled' => true,
            'is_custom' => true,
            'owner_target_id' => $target->id,
        ]);

        $target->commands()->syncWithoutDetaching([$created->id]);

        return $created;
    }

    public function updateOnTarget(MonitorTarget $target, int $commandId, string $label, string $command, string $kind): MonitorCommand
    {
        $label = trim($label);
        $command = trim($command);
        $existing = $target->commands()->where('monitor_commands.id', $commandId)->first();

        if ($existing === null || ! $existing->isCustom() || (int) $existing->owner_target_id !== $target->id) {
            throw new RemoteCommandException('Solo se pueden editar los comandos creados para este sitio.');
        }

        if ($label === '') {
            throw new RemoteCommandException('Indique un nombre para reconocer el comando en la lista.');
        }

        if (! in_array($kind, ['query', 'change'], true)) {
            throw new RemoteCommandException('El tipo debe ser consulta o cambio.');
        }

        if (! self::commandIsSafe($command)) {
            throw new RemoteCommandException(
                'El comando o alias no es válido. Use solo letras, números, espacios, - _ / . : =. Sin ; | & $ ` ni paréntesis.',
            );
        }

        $duplicate = $target->commands()
            ->where('monitor_commands.command', $command)
            ->where('monitor_commands.id', '!=', $existing->id)
            ->exists();

        if ($duplicate) {
            throw new RemoteCommandException('Ese comando ya está en la lista de este sitio.');
        }

        $existing->fill([
            'label' => $label,
            'command' => $command,
            'kind' => $kind,
        ])->save();

        return $existing;
    }

    public function removeFromTarget(MonitorTarget $target, int $commandId): void
    {
        $command = $target->commands()->where('monitor_commands.id', $commandId)->first();

        if ($command === null) {
            return;
        }

        $target->commands()->detach($command->id);

        if ($command->isCustom() && (int) $command->owner_target_id === $target->id) {
            $command->delete();
        }
    }

    /**
     * @return Collection<int, MonitorCommand>
     */
    public static function catalog(?int $targetId = null): Collection
    {
        return MonitorCommand::query()
            ->enabled()
            ->where(function ($query) use ($targetId): void {
                $query->where('is_custom', false);

                if ($targetId !== null) {
                    $query->orWhere('owner_target_id', $targetId);
                }
            })
            ->get();
    }

    private function truncate(string $output): string
    {
        $max = (int) config('monitor.remote_commands.output_max_bytes', 32768);
        $clean = str_replace("\0", '', $output);

        if (! mb_check_encoding($clean, 'UTF-8')) {
            $clean = mb_convert_encoding($clean, 'UTF-8', 'ISO-8859-1') ?: $clean;
        }

        if (strlen($clean) <= $max) {
            return $clean;
        }

        return substr($clean, 0, $max)."\n… [salida recortada]";
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
