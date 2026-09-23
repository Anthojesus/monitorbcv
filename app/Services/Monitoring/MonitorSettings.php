<?php

namespace App\Services\Monitoring;

use App\Models\MonitorIntervalOption;
use App\Models\MonitorSetting;
use App\Models\MonitorTarget;
use Illuminate\Validation\ValidationException;

class MonitorSettings
{
    public const CHART_HISTORY_MAX_DAYS = 'chart_history_max_days';

    public const DEFAULT_CHART_HISTORY_MAX_DAYS = 30;

    public function chartHistoryMaxDays(): int
    {
        $value = (int) (MonitorSetting::getValue(self::CHART_HISTORY_MAX_DAYS, (string) self::DEFAULT_CHART_HISTORY_MAX_DAYS) ?? self::DEFAULT_CHART_HISTORY_MAX_DAYS);

        return max(1, min(365, $value));
    }

    public function setChartHistoryMaxDays(int $days): void
    {
        MonitorSetting::setValue(self::CHART_HISTORY_MAX_DAYS, (string) max(1, min(365, $days)));
    }

    /**
     * @return list<int>
     */
    public function intervalSeconds(): array
    {
        $seconds = MonitorIntervalOption::seconds();

        return $seconds !== [] ? $seconds : [5];
    }

    public function addInterval(int $seconds): MonitorIntervalOption
    {
        if ($seconds < 1 || $seconds > 60) {
            throw ValidationException::withMessages([
                'new_interval_seconds' => 'El intervalo debe estar entre 1 y 60 segundos.',
            ]);
        }

        $existing = MonitorIntervalOption::query()->where('seconds', $seconds)->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'new_interval_seconds' => 'Ese intervalo ya está en la lista.',
            ]);
        }

        return MonitorIntervalOption::query()->create(['seconds' => $seconds]);
    }

    public function removeInterval(int $id): void
    {
        $option = MonitorIntervalOption::query()->findOrFail($id);

        if (MonitorIntervalOption::query()->count() <= 1) {
            throw ValidationException::withMessages([
                'intervals' => 'Debe quedar al menos un intervalo disponible.',
            ]);
        }

        if (MonitorTarget::query()->where('interval_seconds', $option->seconds)->exists()) {
            throw ValidationException::withMessages([
                'intervals' => 'Hay sitios usando '.$option->seconds.'s. Cámbialos antes de quitar esta opción.',
            ]);
        }

        $option->delete();
    }
}
