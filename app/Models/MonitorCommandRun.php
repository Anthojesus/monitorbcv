<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $monitor_target_id
 * @property int|null $monitor_command_id
 * @property int|null $user_id
 * @property string $kind
 * @property string $command_label
 * @property string $command_snapshot
 * @property string $host
 * @property string $username
 * @property string $status
 * @property int|null $exit_code
 * @property string|null $stdout
 * @property string|null $stderr
 * @property string|null $error_message
 * @property int|null $duration_ms
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property-read MonitorTarget $target
 * @property-read MonitorCommand|null $command
 * @property-read User|null $user
 */
#[Fillable([
    'monitor_target_id',
    'monitor_command_id',
    'user_id',
    'kind',
    'command_label',
    'command_snapshot',
    'host',
    'username',
    'status',
    'exit_code',
    'stdout',
    'stderr',
    'error_message',
    'duration_ms',
    'started_at',
    'finished_at',
])]
class MonitorCommandRun extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MonitorTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(MonitorTarget::class, 'monitor_target_id');
    }

    /**
     * @return BelongsTo<MonitorCommand, $this>
     */
    public function command(): BelongsTo
    {
        return $this->belongsTo(MonitorCommand::class, 'monitor_command_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function succeeded(): bool
    {
        return $this->status === 'ok';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'ok' => 'OK',
            'failed' => 'Falló',
            'timeout' => 'Timeout',
            'auth_failed' => 'Auth fallida',
            'denied' => 'Denegado',
            'running' => 'En curso',
            default => $this->status,
        };
    }

    public function displayOutput(): string
    {
        $parts = [];

        foreach ([(string) $this->stdout, (string) $this->stderr] as $chunk) {
            $chunk = trim($chunk);

            if ($chunk !== '' && ! in_array($chunk, $parts, true)) {
                $parts[] = $chunk;
            }
        }

        if ($parts === []) {
            return $this->error_message ?: 'Sin salida.';
        }

        return implode("\n\n--- stderr ---\n", $parts);
    }
}
