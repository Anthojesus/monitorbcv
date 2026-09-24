<?php

namespace App\Console\Commands;

use App\Models\MonitorCheck;
use App\Models\MonitorCommand;
use App\Models\MonitorSetting;
use App\Models\MonitorTarget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MonitorSeedCatalogCommand extends Command
{
    protected $signature = 'monitor:seed-catalog
        {--demo-checks : Genera historial de 15 minutos para los gráficos}
        {--force : No pedir confirmación en producción}';

    protected $description = 'Crea en este ambiente los destinos y ajustes del catálogo de desarrollo';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force') && ! $this->confirm('¿Crear el catálogo de desarrollo en PRODUCCIÓN?')) {
            return self::SUCCESS;
        }

        $catalog = $this->catalog();

        if ($catalog === null) {
            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;

        foreach ($catalog['sites'] as $site) {
            $target = MonitorTarget::query()->updateOrCreate(
                [
                    'name' => $site['name'],
                    'probe_origin' => $site['probe_origin'],
                ],
                [
                    'url' => $site['url'],
                    'kind' => $site['kind'],
                    'method' => $site['method'],
                    'interval_seconds' => $site['interval_seconds'],
                    'timeout_seconds' => $site['timeout_seconds'],
                    'expected_status' => $site['expected_status'],
                    'expected_keyword' => $site['expected_keyword'],
                    'verify_ssl' => $site['verify_ssl'],
                    'is_enabled' => $site['is_enabled'],
                ],
            );

            $target->wasRecentlyCreated ? $created++ : $updated++;
            $this->attachQueryCommands($target);
        }

        foreach ($catalog['settings'] as $key => $value) {
            MonitorSetting::setValue($key, (string) $value);
        }

        $this->info("Destinos creados: {$created}. Actualizados: {$updated}.");

        if ($this->option('demo-checks')) {
            $this->seedDemoChecks();
        }

        return self::SUCCESS;
    }

    /**
     * @return array{sites: list<array<string, mixed>>, settings: array<string, string>}|null
     */
    private function catalog(): ?array
    {
        $path = database_path('data/monitor-catalog.json');

        if (! File::isFile($path)) {
            $this->error('No existe database/data/monitor-catalog.json');

            return null;
        }

        /** @var array{sites?: list<array<string, mixed>>, settings?: array<string, string>} $catalog */
        $catalog = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        return [
            'sites' => array_values($catalog['sites'] ?? []),
            'settings' => $catalog['settings'] ?? ['chart_history_max_days' => '30'],
        ];
    }

    private function attachQueryCommands(MonitorTarget $target): void
    {
        $ids = MonitorCommand::query()
            ->where('kind', 'query')
            ->where('is_enabled', true)
            ->where('is_custom', false)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        $target->commands()->syncWithoutDetaching($ids->all());
    }

    private function seedDemoChecks(): void
    {
        $targets = MonitorTarget::query()->where('is_enabled', true)->get();
        $now = now();
        $inserted = 0;

        foreach ($targets as $target) {
            $hasRecent = $target->checks()
                ->where('checked_at', '>=', $now->copy()->subMinutes(20))
                ->exists();

            if ($hasRecent) {
                continue;
            }

            $latest = null;

            for ($offset = 60; $offset >= 0; $offset--) {
                $checkedAt = $now->copy()->subSeconds($offset * 15);
                $total = 80 + (($target->id * 13 + $offset * 7) % 140);
                $ok = true;
                $status = $target->kind === 'health' ? 200 : 200;
                $payload = $this->demoPayload($target, $total, $ok, $status);

                MonitorCheck::query()->insert([
                    'monitor_target_id' => $target->id,
                    'ok' => $ok,
                    'status_code' => $status,
                    'total_ms' => $total,
                    'availability_reason' => 'ok',
                    'payload' => json_encode($payload),
                    'checked_at' => $checkedAt,
                ]);

                $inserted++;
                $latest = ['ok' => $ok, 'status' => $status, 'total' => $total, 'at' => $checkedAt];
            }

            if ($latest !== null) {
                $target->forceFill([
                    'last_ok' => $latest['ok'],
                    'last_status_code' => $latest['status'],
                    'last_total_ms' => $latest['total'],
                    'last_checked_at' => $latest['at'],
                ])->save();
            }
        }

        $this->info("Chequeos de demostración: {$inserted}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function demoPayload(MonitorTarget $target, int $total, bool $ok, int $status): array
    {
        $dns = max(4, (int) round($total * 0.08));
        $tcp = max(6, (int) round($total * 0.12));

        $payload = [
            'ok' => $ok,
            'engine' => 'fastapi',
            'probe_origin' => $target->probeOrigin(),
            'timings_ms' => [
                'dns' => $dns,
                'tcp' => $tcp,
                'tls' => max(10, (int) round($total * 0.2)),
                'ttfb' => max(20, (int) round($total * 0.45)),
                'total' => $total,
            ],
            'http' => [
                'status' => $status,
            ],
        ];

        if ($target->isHealth()) {
            $payload['health'] = [
                'format' => 'microprofile',
                'status' => 'UP',
                'up' => true,
                'up_count' => 2,
                'down_count' => 0,
                'checks' => [
                    ['name' => 'LDAP check', 'status' => 'UP', 'up' => true, 'detail' => 'status: ALIVE'],
                    ['name' => 'Base de datos check', 'status' => 'UP', 'up' => true, 'detail' => 'status: ALIVE'],
                ],
            ];
        }

        return $payload;
    }
}
