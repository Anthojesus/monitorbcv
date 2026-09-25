<?php

namespace App\Services\Monitoring;

use App\Models\MonitorCheck;
use App\Models\MonitorTarget;
use DateTimeInterface;
use Illuminate\Support\Collection;

class DashboardMetrics
{
    /**
     * @return array<string, mixed>
     */
    public function all(?DateTimeInterface $since = null): array
    {
        $since ??= now()->subDay();
        $targets = MonitorTarget::query()->with([
            'checks' => fn ($query) => $query->latest('checked_at')->limit(40),
            'proxy.checks' => fn ($query) => $query->latest('checked_at')->limit(8),
        ])->get();
        $checks = MonitorCheck::query()
            ->where('checked_at', '>=', $since)
            ->latest('checked_at')
            ->limit(400)
            ->get();
        $probe = app(FastApiProbe::class);
        $runtime = $probe->runtime();

        return [
            'engine' => $probe->engineLabel(),
            'runtime' => $runtime,
            'http' => $this->http($targets, $checks, $runtime, $since),
            'java' => $this->placeholder('java'),
            'php_logs' => $this->placeholder('php'),
            'system' => $this->placeholder('system'),
        ];
    }

    /**
     * @param  Collection<int, MonitorTarget>  $targets
     * @param  Collection<int, MonitorCheck>  $checks
     * @param  array<string, mixed>  $runtime
     * @return array<string, mixed>
     */
    private function http(Collection $targets, Collection $checks, array $runtime, DateTimeInterface $since): array
    {
        $up = $targets->where('last_ok', true)->count();
        $down = $targets->where('last_ok', false)->count();
        $unknown = $targets->whereNull('last_ok')->count();
        $byOrigin = $this->byOrigin($targets, $runtime);

        $statusTotal = max(1, $checks->count());
        $statusBars = collect([
            ['code' => '2xx', 'label' => 'Éxito', 'tone' => 'emerald', 'count' => $checks->filter(fn (MonitorCheck $check) => $this->inFamily($check->status_code, 200))->count()],
            ['code' => '3xx', 'label' => 'Redirect', 'tone' => 'sky', 'count' => $checks->filter(fn (MonitorCheck $check) => $this->inFamily($check->status_code, 300))->count()],
            ['code' => '4xx', 'label' => 'Cliente', 'tone' => 'amber', 'count' => $checks->filter(fn (MonitorCheck $check) => $this->inFamily($check->status_code, 400))->count()],
            ['code' => '5xx', 'label' => 'Servidor', 'tone' => 'rose', 'count' => $checks->filter(fn (MonitorCheck $check) => $this->inFamily($check->status_code, 500))->count()],
        ])->map(fn (array $row) => $row + [
            'pct' => (int) round(100 * $row['count'] / $statusTotal),
        ])->all();

        $windowStats = CheckStats::fromChecks($checks);
        $avgMs = $windowStats['avg_total'];
        $avgTtfb = $windowStats['avg_ttfb'];
        $networkSamples = $checks->map(fn (MonitorCheck $check) => MonitorCopy::network($check->payload)['ms'])->filter(fn ($ms) => $ms !== null);
        $avgNetMs = $networkSamples->isEmpty() ? 0 : (int) round($networkSamples->avg());
        $sslWarnDays = max(1, (int) config('monitor.degradation.ssl_warning_days', 15));
        $condition = app(SiteCondition::class);
        $proxyCorrelation = app(ProxyCorrelation::class);
        $conditions = [];
        foreach ($targets as $target) {
            $originHealthy = (bool) data_get($runtime, $target->probeOrigin().'.api.ok');
            $conditions[$target->id] = $condition->evaluate($target, $target->checks, $originHealthy);
        }
        $diagnoses = app(OriginCorrelation::class)->diagnoses($targets, $conditions);
        $degraded = collect($conditions)->where('key', 'degraded')->count();
        $sslSoon = $targets->filter(function (MonitorTarget $target) use ($sslWarnDays) {
            $days = data_get($target->checks->first()?->payload, 'tls.certificate.days_remaining');

            return is_numeric($days) && (int) $days < $sslWarnDays;
        })->count();

        $successOk = $checks->where('ok', true)->count();
        $successTotal = $checks->count();
        $successPct = $successTotal === 0 ? 0 : (int) round(100 * $successOk / $successTotal);

        $health = match (true) {
            $targets->isEmpty() => 'empty',
            $down > 0 => 'critical',
            $degraded > 0 || $sslSoon > 0 || $unknown > 0 || $diagnoses !== [] => 'attention',
            default => 'operational',
        };

        return [
            'up' => $up,
            'down' => $down,
            'degraded' => $degraded,
            'unknown' => $unknown,
            'total' => $targets->count(),
            'diagnoses' => $diagnoses,
            'p95_total' => $windowStats['p95_total'],
            'p99_total' => $windowStats['p99_total'],
            'p95_ttfb' => $windowStats['p95_ttfb'],
            'p99_ttfb' => $windowStats['p99_ttfb'],
            'by_origin' => $byOrigin,
            'stale_origins' => collect($byOrigin)
                ->filter(fn (array $row): bool => $row['stale'])
                ->pluck('label')
                ->values()
                ->all(),
            'avg_ms' => $avgMs,
            'avg_ttfb' => $avgTtfb,
            'avg_net_ms' => $avgNetMs,
            'avg_net_label' => MonitorCopy::network(['timings_ms' => ['dns' => $avgNetMs, 'tcp' => 0]])['label'],
            'ssl_expiring' => $sslSoon,
            'health' => $health,
            'last_run' => optional($checks->first()?->checked_at)->diffForHumans() ?? 'sin chequeos',
            'success_pct' => $successPct,
            'success_ok' => $successOk,
            'success_total' => $successTotal,
            'site_series' => $siteSeries = app(SiteLineChart::class)->series($targets, $since),
            'site_series_internal' => array_values(array_filter(
                $siteSeries,
                fn (array $series): bool => ($series['probe_origin'] ?? MonitorTarget::ORIGIN_INTERNAL) === MonitorTarget::ORIGIN_INTERNAL
            )),
            'site_series_external' => array_values(array_filter(
                $siteSeries,
                fn (array $series): bool => ($series['probe_origin'] ?? '') === MonitorTarget::ORIGIN_EXTERNAL
            )),
            'status_codes' => $statusBars,
            'services' => $targets
                ->filter(fn (MonitorTarget $target) => $target->kind === 'health')
                ->values()
                ->map(function (MonitorTarget $target) {
                    $payload = $target->checks->first()?->payload ?? [];
                    $health = data_get($payload, 'health', []);

                    return [
                        'id' => $target->id,
                        'name' => $target->name,
                        'url' => $target->url,
                        'probe_origin' => $target->probeOrigin(),
                        'origin_label' => MonitorCopy::originLabel($target->probeOrigin()),
                        'ok' => $target->last_ok,
                        'ms' => $target->last_total_ms,
                        'network' => MonitorCopy::network(is_array($payload) ? $payload : []),
                        'checked_at' => $target->last_checked_at?->diffForHumans(),
                        'status' => data_get($health, 'status'),
                        'format' => data_get($health, 'format'),
                        'up_count' => (int) data_get($health, 'up_count', 0),
                        'down_count' => (int) data_get($health, 'down_count', 0),
                        'checks' => data_get($health, 'checks', []),
                    ];
                })
                ->all(),
            'targets' => $targets
                ->sortBy(fn (MonitorTarget $target) => $target->last_ok === false ? 0 : 1)
                ->values()
                ->map(function (MonitorTarget $target) use ($conditions, $diagnoses, $runtime, $condition, $proxyCorrelation) {
                    $recent = $target->checks;
                    $latest = $recent->first();
                    $payload = $latest instanceof MonitorCheck ? $latest->payload : [];
                    $reliability = MonitorCopy::reliability($recent, $target->last_ok);
                    $reason = data_get($payload, 'availability.reason');
                    $originHealthy = (bool) data_get($runtime, $target->probeOrigin().'.api.ok');
                    $probeUnavailable = ($latest instanceof MonitorCheck && $latest->isProbeUnavailable())
                        || $reason === 'probe_unavailable';
                    $siteCondition = $conditions[$target->id] ?? ['key' => 'pending'];
                    $pair = collect($diagnoses)->first(
                        fn (array $row): bool => ($row['internal_id'] ?? 0) === $target->id || ($row['external_id'] ?? 0) === $target->id
                    );
                    $proxy = $target->proxy;
                    $proxyCondition = $proxy instanceof MonitorTarget
                        ? ($conditions[$proxy->id] ?? $condition->evaluate(
                            $proxy,
                            $proxy->checks,
                            (bool) data_get($runtime, $proxy->probeOrigin().'.api.ok'),
                        ))
                        : null;
                    $proxyDiagnosis = $proxyCorrelation->diagnose($target, $proxy, $siteCondition, $proxyCondition);

                    return [
                        'id' => $target->id,
                        'name' => $target->name,
                        'url' => $target->url,
                        'kind' => $target->kind,
                        'proxy_name' => $proxy?->name,
                        'proxy_id' => $proxy?->id,
                        'proxy_diagnosis' => $proxyDiagnosis,
                        'probe_origin' => $target->probeOrigin(),
                        'origin_label' => MonitorCopy::originLabel($target->probeOrigin()),
                        'probe_unavailable' => $probeUnavailable && ! $originHealthy,
                        'condition' => $siteCondition,
                        'diagnosis' => $pair,
                        'health' => data_get($payload, 'health'),
                        'ok' => $target->last_ok,
                        'status' => $target->last_status_code,
                        'ms' => $target->last_total_ms,
                        'ttfb' => data_get($payload, 'timings_ms.ttfb'),
                        'network' => MonitorCopy::network($payload),
                        'web_server' => MonitorCopy::webServer($payload),
                        'reason' => $reason,
                        'reason_label' => MonitorCopy::reason(is_string($reason) ? $reason : null),
                        'reason_hint' => ($probeUnavailable && $originHealthy)
                            ? null
                            : MonitorCopy::reasonHint(
                                is_string($reason) ? $reason : null,
                                $target->last_status_code,
                                $target->probeOrigin(),
                            ),
                        'checked_at' => $target->last_checked_at?->diffForHumans(),
                        'ssl_days' => data_get($payload, 'tls.certificate.days_remaining'),
                        'security_grade' => data_get($payload, 'security.grade'),
                        'success_pct' => $reliability['percent'],
                        'reliability' => $reliability,
                    ];
                })
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, MonitorTarget>  $targets
     * @param  array<string, mixed>  $runtime
     * @return array<string, array<string, mixed>>
     */
    private function byOrigin(Collection $targets, array $runtime): array
    {
        $rows = [];

        foreach ([MonitorTarget::ORIGIN_INTERNAL => 'Interior', MonitorTarget::ORIGIN_EXTERNAL => 'Exterior'] as $origin => $label) {
            $group = $targets->filter(fn (MonitorTarget $target): bool => $target->probeOrigin() === $origin);
            $probeOk = (bool) data_get($runtime, $origin.'.api.ok');
            $stale = $group->isNotEmpty() && ! $probeOk;

            $rows[$origin] = [
                'origin' => $origin,
                'label' => $label,
                'up' => $group->where('last_ok', true)->count(),
                'down' => $group->where('last_ok', false)->count(),
                'unknown' => $group->whereNull('last_ok')->count(),
                'total' => $group->count(),
                'probe_ok' => $probeOk,
                'stale' => $stale,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function placeholder(string $kind): array
    {
        return [
            'ready' => false,
            'kind' => $kind,
            'message' => match ($kind) {
                'java' => 'La consola de logs Java se conectará a las rutas Logback/Log4j en la siguiente fase.',
                'php' => 'La lectura de laravel.log y php-fpm se activará junto al agente FastAPI.',
                default => 'CPU, memoria y servicios (Redis, MySQL) se recolectarán desde el worker Python.',
            },
        ];
    }

    private function inFamily(?int $status, int $family): bool
    {
        return $status !== null && $status >= $family && $status < $family + 100;
    }
}
