<?php

namespace App\Console\Commands;

use App\Models\MonitorTarget;
use App\Services\Monitoring\FastApiProbe;
use App\Services\Monitoring\MonitorEngine;
use Illuminate\Console\Command;

class MonitorRunCommand extends Command
{
    protected $signature = 'monitor:run {--id= : ID de un sitio específico} {--force : Ignora el intervalo y chequea todos}';

    protected $description = 'Ejecuta el probe HTTP y guarda el JSON detallado de cada sitio';

    public function handle(MonitorEngine $engine, FastApiProbe $probe): int
    {
        $probe->refreshRuntime();

        if ($this->option('id')) {
            $target = MonitorTarget::query()->find($this->option('id'));

            if ($target === null) {
                $this->error('Sitio no encontrado.');

                return self::FAILURE;
            }

            $check = $engine->run($target);
            $this->line(sprintf(
                '%s %s  %s  %sms  %s',
                $check->ok ? 'OK' : 'DOWN',
                $target->name,
                $check->status_code ?? '-',
                $check->total_ms ?? '-',
                $check->availability_reason ?? '',
            ));

            return self::SUCCESS;
        }

        if ($this->option('force')) {
            $targets = MonitorTarget::query()->where('is_enabled', true)->get();

            foreach ($targets as $target) {
                $check = $engine->run($target);
                $this->line(sprintf(
                    '%s %s  %s  %sms  %s',
                    $check->ok ? 'OK' : 'DOWN',
                    $target->name,
                    $check->status_code ?? '-',
                    $check->total_ms ?? '-',
                    $check->availability_reason ?? '',
                ));
            }

            return self::SUCCESS;
        }

        $ran = $engine->runDue();
        $this->info($ran === 0 ? 'Nada vencido por ahora.' : "Chequeos ejecutados: {$ran}");

        return self::SUCCESS;
    }
}
