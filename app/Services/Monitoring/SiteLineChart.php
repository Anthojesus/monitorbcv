<?php

namespace App\Services\Monitoring;

use App\Models\MonitorCheck;
use App\Models\MonitorTarget;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Collection;

class SiteLineChart
{
    /**
     * @var list<string>
     */
    public const COLORS = [
        '#38bdf8',
        '#a78bfa',
        '#34d399',
        '#f472b6',
        '#fbbf24',
        '#fb7185',
        '#22d3ee',
        '#818cf8',
        '#4ade80',
        '#e879f9',
        '#2dd4bf',
        '#f59e0b',
    ];

    /**
     * @param  Collection<int, MonitorTarget>  $targets
     * @return list<array<string, mixed>>
     */
    public function series(Collection $targets, ?DateTimeInterface $since = null): array
    {
        $timeFormat = $this->timeFormat($since, now());
        $https = $targets
            ->filter(fn (MonitorTarget $target) => str_starts_with(strtolower($target->url), 'https://'))
            ->sortBy(fn (MonitorTarget $target) => $target->last_ok === false ? 0 : 1)
            ->values();
        $grouped = $this->checksForTargets($https, $since);

        return $https
            ->map(function (MonitorTarget $target) use ($timeFormat, $grouped) {
                $checks = $grouped->get($target->id) ?? $grouped->get((string) $target->id) ?? new Collection;
                $points = $checks
                    ->sortBy('checked_at')
                    ->values()
                    ->map(function (MonitorCheck $check) use ($timeFormat) {
                        if ($check->isProbeUnavailable()) {
                            return null;
                        }

                        $payload = is_array($check->payload) ? $check->payload : [];

                        return [
                            'ts' => $check->checked_at?->timestamp ?? 0,
                            'time' => $check->checked_at?->timezone(config('app.timezone'))->format($timeFormat) ?? '',
                            'total' => (int) ($check->total_ms ?? 0),
                            'net' => (int) (MonitorCopy::network($payload)['ms'] ?? 0),
                            'ok' => (bool) $check->ok,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();

                $latest = $checks->sortByDesc('checked_at')->first();

                return [
                    'id' => $target->id,
                    'name' => $target->name,
                    'url' => $target->url,
                    'probe_origin' => $target->probeOrigin(),
                    'ok' => $target->last_ok,
                    'color' => self::COLORS[$target->id % count(self::COLORS)],
                    'latest_ms' => $target->last_total_ms,
                    'latest_net' => MonitorCopy::network(is_array($latest?->payload) ? $latest->payload : [])['ms'],
                    'points' => $points,
                ];
            })
            ->filter(fn (array $series) => $series['points'] !== [])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $series
     * @param  list<int>  $hiddenIds
     * @return array<string, mixed>
     */
    public function layout(array $series, string $metric, array $hiddenIds, ?DateTimeInterface $windowStart = null, ?DateTimeInterface $windowEnd = null): array
    {
        $width = 1600;
        $height = 420;
        $left = 58;
        $right = 20;
        $top = 22;
        $bottom = 36;
        $plotWidth = $width - $left - $right;
        $plotHeight = $height - $top - $bottom;
        $field = $metric === 'net' ? 'net' : 'total';
        $windowEnd ??= now();

        $visible = collect($series)->reject(fn (array $row) => in_array($row['id'], $hiddenIds, true))->values();
        $timestamps = $visible->flatMap(fn (array $row) => collect($row['points'])->pluck('ts'))->filter();
        $values = $visible->flatMap(fn (array $row) => collect($row['points'])->pluck($field));

        $minTs = $windowStart instanceof DateTimeInterface
            ? $windowStart->getTimestamp()
            : (int) ($timestamps->min() ?: $windowEnd->getTimestamp());
        $maxTs = max($windowEnd->getTimestamp(), (int) ($timestamps->max() ?: $windowEnd->getTimestamp()));
        if ($minTs === $maxTs) {
            $maxTs = $minTs + 1;
        }

        $maxValue = $this->niceCeiling((int) ($values->max() ?: 1));
        $span = max(1, $maxTs - $minTs);

        $x = fn (int $ts): float => $left + ((($ts - $minTs) / $span) * $plotWidth);
        $y = fn (int $value): float => $top + $plotHeight - (($value / $maxValue) * $plotHeight);

        $lines = $visible->map(function (array $row) use ($field, $x, $y, $top, $plotHeight) {
            $plotted = [];
            $path = [];

            foreach (array_values($row['points']) as $index => $point) {
                $px = round($x((int) $point['ts']), 1);
                $py = round($y((int) $point[$field]), 1);
                $plotted[] = [
                    'x' => $px,
                    'y' => $py,
                    'ms' => (int) $point[$field],
                    'prev_ms' => $index > 0 ? (int) $row['points'][$index - 1][$field] : null,
                    'time' => $point['time'],
                    'ok' => $point['ok'],
                ];
                $path[] = ($index === 0 ? 'M' : 'L').$px.' '.$py;
            }

            $last = $plotted === [] ? null : $plotted[array_key_last($plotted)];
            $first = $plotted === [] ? null : $plotted[0];
            $baseline = $top + $plotHeight;
            $area = ($first && $last && $path !== [])
                ? implode(' ', $path).' L '.$last['x'].' '.$baseline.' L '.$first['x'].' '.$baseline.' Z'
                : '';

            return [
                'id' => $row['id'],
                'name' => $row['name'],
                'url' => $row['url'] ?? '',
                'color' => $row['color'],
                'ok' => $row['ok'],
                'path' => implode(' ', $path),
                'area' => $area,
                'points' => $plotted,
                'label_x' => $last['x'] ?? 0,
                'label_y' => $last['y'] ?? 0,
                'label_ms' => $last['ms'] ?? null,
            ];
        })->all();

        $tz = (string) config('app.timezone');
        $axisFormat = $this->timeFormat(
            Carbon::createFromTimestamp($minTs, $tz),
            Carbon::createFromTimestamp($maxTs, $tz),
        );
        $startLabel = Carbon::createFromTimestamp($minTs, $tz)->format($axisFormat);
        $endLabel = Carbon::createFromTimestamp($maxTs, $tz)->format($axisFormat);
        $midTs = (int) round(($minTs + $maxTs) / 2);
        $midLabel = Carbon::createFromTimestamp($midTs, $tz)->format($axisFormat);
        $step = max(1, (int) ($maxValue / 4));
        $ticks = array_values(array_unique([0, $step, $step * 2, $step * 3, $maxValue]));

        return [
            'width' => $width,
            'height' => $height,
            'left' => $left,
            'right' => $right,
            'top' => $top,
            'bottom' => $bottom,
            'plot_width' => $plotWidth,
            'plot_bottom' => $top + $plotHeight,
            'metric' => $field,
            'max' => $maxValue,
            'ticks' => $ticks,
            'y' => collect($ticks)->mapWithKeys(fn (int $tick) => [$tick => $y($tick)])->all(),
            'x_labels' => [
                ['x' => $left, 'time' => $startLabel, 'anchor' => 'start'],
                ['x' => $left + ($plotWidth / 2), 'time' => $midLabel, 'anchor' => 'middle'],
                ['x' => $width - $right, 'time' => $endLabel, 'anchor' => 'end'],
            ],
            'lines' => $lines,
            'start' => $startLabel,
            'end' => $endLabel,
            'visible_count' => $visible->count(),
            'site_count' => count($series),
        ];
    }

    /**
     * @param  Collection<int, MonitorTarget>  $targets
     * @return Collection<int|string, Collection<int, MonitorCheck>>
     */
    private function checksForTargets(Collection $targets, ?DateTimeInterface $since): Collection
    {
        if ($targets->isEmpty()) {
            return new Collection;
        }

        $since ??= now()->subDay();
        $ids = $targets->pluck('id');
        $limit = min(400, max(40, $ids->count() * 60));

        return MonitorCheck::query()
            ->whereIn('monitor_target_id', $ids)
            ->where('checked_at', '>=', $since)
            ->latest('checked_at')
            ->limit($limit)
            ->get(['id', 'monitor_target_id', 'ok', 'total_ms', 'payload', 'checked_at', 'availability_reason'])
            ->groupBy('monitor_target_id');
    }

    /**
     * @return Collection<int, MonitorCheck>
     */
    private function checksForSeries(MonitorTarget $target, ?DateTimeInterface $since): Collection
    {
        if ($since === null && $target->relationLoaded('checks')) {
            return $target->checks;
        }

        return $this->checksForChart($target->id, $since ?? now()->subDay());
    }

    /**
     * @return Collection<int, MonitorCheck>
     */
    public function checksForChart(int $targetId, DateTimeInterface $since, int $maxPoints = 240): Collection
    {
        $query = MonitorCheck::query()
            ->where('monitor_target_id', $targetId)
            ->where('checked_at', '>=', $since);

        $count = (clone $query)->count();

        if ($count === 0) {
            return new Collection;
        }

        if ($count <= $maxPoints) {
            return $query->orderBy('checked_at')->get();
        }

        $driver = MonitorCheck::query()->getConnection()->getDriverName();
        $epochSql = $driver === 'sqlite'
            ? "CAST(strftime('%s', checked_at) AS INTEGER)"
            : 'UNIX_TIMESTAMP(checked_at)';
        $bucket = max(1, (int) ceil(max(1, now()->getTimestamp() - $since->getTimestamp()) / $maxPoints));
        // SQLite has no FLOOR(); integer CAST truncates the same way for positive timestamps.
        $bucketSql = 'CAST(('.$epochSql.') / ? AS INTEGER)';

        $ids = MonitorCheck::query()
            ->where('monitor_target_id', $targetId)
            ->where('checked_at', '>=', $since)
            ->groupByRaw($bucketSql, [$bucket])
            ->selectRaw('MAX(id) as id')
            ->pluck('id');

        $latestId = (clone $query)->orderByDesc('checked_at')->orderByDesc('id')->value('id');
        if ($latestId !== null && ! $ids->contains($latestId)) {
            $ids->push($latestId);
        }

        return MonitorCheck::query()->whereIn('id', $ids)->orderBy('checked_at')->get();
    }

    public function timeFormat(?DateTimeInterface $from, ?DateTimeInterface $to = null): string
    {
        $to ??= now();
        $from ??= $to;

        $span = max(1, $to->getTimestamp() - $from->getTimestamp());

        return match (true) {
            $span <= 2 * 3600 => 'H:i:s',
            $span <= 36 * 3600 => 'H:i',
            default => 'd/m H:i',
        };
    }

    private function niceCeiling(int $value): int
    {
        $value = max(1, $value);
        $exp = 10 ** max(0, (int) floor(log10($value)));

        foreach ([1, 2, 2.5, 5, 10] as $mult) {
            $candidate = (int) ceil($mult * $exp);
            if ($candidate >= $value) {
                return $candidate;
            }
        }

        return 10 * $exp;
    }
}
