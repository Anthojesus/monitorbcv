<?php

namespace App\Services\Monitoring;

use App\Models\MonitorCheck;
use App\Models\MonitorTarget;
use Illuminate\Support\Collection;

class SiteCondition
{
    /**
     * @param  Collection<int, MonitorCheck>  $recent  Más recientes primero
     * @return array<string, mixed>
     */
    public function evaluate(MonitorTarget $target, Collection $recent, ?bool $probeHealthy = null): array
    {
        $latest = $recent->first();
        $payload = is_array($latest?->payload) ? $latest->payload : [];
        $reason = $latest?->availability_reason ?? data_get($payload, 'availability.reason');
        $reason = is_string($reason) ? $reason : null;
        $verdict = $this->verdict($target, $latest instanceof MonitorCheck ? $latest : null, $payload);
        $unmonitored = $latest instanceof MonitorCheck
            ? $latest->isProbeUnavailable()
            : ((bool) data_get($payload, 'probe_unavailable') || $reason === 'probe_unavailable');
        if ($unmonitored && $probeHealthy !== true) {
            return $this->row(
                'unmonitored',
                'No monitoreado',
                'amber',
                'warning',
                'La sonda de este origen no está chequeando. El último verde no está confirmado.',
                [],
                $verdict,
            );
        }

        if ($unmonitored && $probeHealthy === true) {
            $side = MonitorCopy::originLabel($target->probeOrigin());

            if ($target->last_ok === null) {
                return $this->row('pending', 'Pendiente', 'zinc', 'info', 'La sonda '.$side.' ya está arriba. Esperando el próximo sondeo.', [], $verdict);
            }

            if ($target->last_ok === false) {
                return $this->row(
                    'down',
                    'DOWN',
                    'rose',
                    'critical',
                    'La sonda '.$side.' ya está arriba. Se conserva el último DOWN hasta el próximo ciclo.',
                    [['key' => $reason ?? 'down', 'text' => MonitorCopy::reason($reason)]],
                    $verdict,
                );
            }

            return $this->row(
                'unknown',
                'Sin confirmar',
                'zinc',
                'warning',
                'La sonda '.$side.' ya está arriba, pero el último sondeo no midió el portal. No asuma que está UP.',
                [],
                $verdict,
            );
        }

        if ($target->last_ok === null && $target->last_status_code === null) {
            return $this->row('pending', 'Pendiente', 'zinc', 'info', 'Aún no hay un sondeo.', [], $verdict);
        }

        if (app(AvailabilityVerdict::class)->pageIncident($target, $target->last_status_code)) {
            $status = $target->last_status_code;
            $detail = MonitorCopy::reason('http_5xx');

            return $this->row(
                'down',
                'DOWN',
                'rose',
                'critical',
                $detail,
                [['key' => 'http_5xx', 'text' => 'HTTP '.$status.' · host alcanzable, página no OK']],
                ['host_ok' => true, 'page_ok' => false],
            );
        }

        if ($target->last_ok === false) {
            return $this->row(
                'down',
                'DOWN',
                'rose',
                'critical',
                MonitorCopy::reason($reason),
                [['key' => $reason ?? 'down', 'text' => MonitorCopy::reason($reason)]],
                $verdict,
            );
        }

        if ($target->last_ok === null) {
            return $this->row('pending', 'Pendiente', 'zinc', 'info', 'Aún no hay un sondeo.', [], $verdict);
        }

        $issues = $this->degradationIssues($target, $recent, $payload);

        if ($issues === []) {
            return $this->row('ok', 'UP', 'lime', 'ok', MonitorCopy::reason($reason), [], $verdict);
        }

        $critical = collect($issues)->contains(fn (array $issue): bool => ($issue['severity'] ?? '') === 'critical');

        return $this->row(
            'degraded',
            'Degradado',
            'amber',
            $critical ? 'critical' : 'warning',
            implode(' · ', array_column($issues, 'text')),
            $issues,
            $verdict,
        );
    }

    /**
     * @param  Collection<int, MonitorCheck>  $recent
     * @param  array<string, mixed>  $payload
     * @return list<array{key: string, text: string, severity: string}>
     */
    private function degradationIssues(MonitorTarget $target, Collection $recent, array $payload): array
    {
        $warningMs = max(1, (int) config('monitor.degradation.ttfb_warning_ms', 1000));
        $criticalMs = max($warningMs, (int) config('monitor.degradation.ttfb_critical_ms', 3000));
        $streakNeed = max(1, (int) config('monitor.degradation.ttfb_warning_streak', 3));
        $sslWarn = max(1, (int) config('monitor.degradation.ssl_warning_days', 15));
        $sslCrit = max(1, (int) config('monitor.degradation.ssl_critical_days', 7));
        $issues = [];

        $sslDays = data_get($payload, 'tls.certificate.days_remaining');
        if (is_numeric($sslDays)) {
            $days = (int) $sslDays;
            if ($days < $sslCrit) {
                $issues[] = ['key' => 'ssl_critical', 'text' => 'SSL vence en '.$days.' días', 'severity' => 'critical'];
            } elseif ($days < $sslWarn) {
                $issues[] = ['key' => 'ssl_warning', 'text' => 'SSL vence en '.$days.' días', 'severity' => 'warning'];
            }
        }

        $status = $target->last_status_code;
        if ($target->isProxy() && $status !== null && $status >= 500) {
            $issues[] = ['key' => 'http_5xx', 'text' => 'HTTP '.$status.' con el host alcanzable', 'severity' => 'critical'];
        } elseif (! $target->isProxy() && $status !== null && $status >= 400 && $status < 500) {
            $issues[] = ['key' => 'http_4xx', 'text' => 'HTTP '.$status.' (página no OK, host alcanzable)', 'severity' => 'warning'];
        }

        $latestTtfb = data_get($payload, 'timings_ms.ttfb');
        if (is_numeric($latestTtfb) && (int) $latestTtfb > $criticalMs) {
            $issues[] = ['key' => 'ttfb_critical', 'text' => 'TTFB '.(int) $latestTtfb.' ms', 'severity' => 'critical'];
        } else {
            $streak = 0;
            foreach ($recent as $check) {
                $checkPayload = is_array($check->payload) ? $check->payload : [];
                if ($check->isProbeUnavailable()) {
                    continue;
                }

                $ttfb = data_get($checkPayload, 'timings_ms.ttfb');
                if (is_numeric($ttfb) && (int) $ttfb > $warningMs) {
                    $streak++;

                    continue;
                }

                break;
            }

            if ($streak >= $streakNeed) {
                $issues[] = [
                    'key' => 'ttfb_warning',
                    'text' => 'TTFB > '.$warningMs.' ms en '.$streak.' chequeos seguidos',
                    'severity' => 'warning',
                ];
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{host_ok: bool|null, page_ok: bool|null}
     */
    private function verdict(MonitorTarget $target, ?MonitorCheck $latest, array $payload): array
    {
        if ($latest instanceof MonitorCheck && $latest->isProbeUnavailable()) {
            return ['host_ok' => null, 'page_ok' => null];
        }

        $host = data_get($payload, 'availability.host_ok', $latest?->hostOk());
        $page = data_get($payload, 'availability.page_ok', $latest?->pageOk());
        $status = $target->last_status_code ?? $latest?->status_code;

        if ($status === null && ! is_bool($host) && ! is_bool($page)) {
            return ['host_ok' => null, 'page_ok' => null];
        }

        if (! is_bool($host)) {
            $host = $status !== null && $status >= 100 && $status <= 599;
        }

        if (! is_bool($page)) {
            $page = app(AvailabilityVerdict::class)->pageOk($target, is_numeric($status) ? (int) $status : null);
        }

        return [
            'host_ok' => $host,
            'page_ok' => $page,
        ];
    }

    /**
     * @param  list<array{key: string, text: string, severity?: string}>  $issues
     * @param  array{host_ok: bool|null, page_ok: bool|null}  $verdict
     * @return array<string, mixed>
     */
    private function row(string $key, string $label, string $tone, string $severity, string $detail, array $issues, array $verdict): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'tone' => $tone,
            'severity' => $severity,
            'detail' => $detail,
            'issues' => $issues,
            'host_ok' => $verdict['host_ok'] ?? null,
            'page_ok' => $verdict['page_ok'] ?? null,
            'slow' => collect($issues)->contains(fn (array $issue): bool => str_starts_with($issue['key'], 'ttfb_')),
        ];
    }
}
