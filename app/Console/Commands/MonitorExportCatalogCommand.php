<?php

namespace App\Console\Commands;

use App\Models\MonitorSetting;
use App\Models\MonitorTarget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MonitorExportCatalogCommand extends Command
{
    protected $signature = 'monitor:export-catalog {--path= : Ruta del JSON de salida}';

    protected $description = 'Exporta destinos y ajustes actuales a database/data/monitor-catalog.json';

    public function handle(): int
    {
        $path = $this->option('path') ?: database_path('data/monitor-catalog.json');

        File::ensureDirectoryExists(dirname($path));

        $sites = MonitorTarget::query()
            ->orderBy('id')
            ->get([
                'name',
                'url',
                'kind',
                'probe_origin',
                'method',
                'interval_seconds',
                'timeout_seconds',
                'expected_status',
                'expected_keyword',
                'verify_ssl',
                'is_enabled',
            ])
            ->map(fn (MonitorTarget $target): array => [
                'name' => $target->name,
                'url' => $target->url,
                'kind' => $target->kind,
                'probe_origin' => $target->probeOrigin(),
                'method' => $target->method,
                'interval_seconds' => $target->interval_seconds,
                'timeout_seconds' => $target->timeout_seconds,
                'expected_status' => $target->expectedStatusCodes(),
                'expected_keyword' => $target->expected_keyword,
                'verify_ssl' => $target->verify_ssl,
                'is_enabled' => $target->is_enabled,
            ])
            ->values()
            ->all();

        $payload = [
            'settings' => [
                'chart_history_max_days' => MonitorSetting::getValue('chart_history_max_days', '30'),
            ],
            'sites' => $sites,
        ];

        File::put(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );

        $this->info('Catálogo exportado: '.$path.' ('.count($sites).' destinos).');

        return self::SUCCESS;
    }
}
