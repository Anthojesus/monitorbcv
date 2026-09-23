<?php

namespace App\Services\Monitoring;

use App\Models\MonitorCheck;
use Illuminate\Support\Collection;

class CheckStats
{
    /**
     * @param  list<int|float>  $values
     */
    public static function percentile(array $values, float $percent): ?int
    {
        $values = array_values(array_filter($values, fn (mixed $value): bool => is_numeric($value)));
        sort($values);

        if ($values === []) {
            return null;
        }

        $rank = (int) ceil(($percent / 100) * count($values)) - 1;
        $rank = max(0, min($rank, count($values) - 1));

        return (int) $values[$rank];
    }

    /**
     * @param  Collection<int, MonitorCheck>  $checks
     * @return array{avg_total: int, avg_ttfb: int, p95_total: int|null, p99_total: int|null, p95_ttfb: int|null, p99_ttfb: int|null, samples: int}
     */
    public static function fromChecks(Collection $checks): array
    {
        $totals = $checks->pluck('total_ms')->filter(fn (mixed $value): bool => is_numeric($value))->map(fn (mixed $value): int => (int) $value)->values()->all();
        $ttfbs = $checks
            ->map(fn (MonitorCheck $check): mixed => data_get($check->payload, 'timings_ms.ttfb'))
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->values()
            ->all();

        return [
            'avg_total' => $totals === [] ? 0 : (int) round(array_sum($totals) / count($totals)),
            'avg_ttfb' => $ttfbs === [] ? 0 : (int) round(array_sum($ttfbs) / count($ttfbs)),
            'p95_total' => self::percentile($totals, 95),
            'p99_total' => self::percentile($totals, 99),
            'p95_ttfb' => self::percentile($ttfbs, 95),
            'p99_ttfb' => self::percentile($ttfbs, 99),
            'samples' => $checks->count(),
        ];
    }
}
