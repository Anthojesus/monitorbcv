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
        $unmonitored = $latest instanceof MonitorCheck
            ? $latest->isProbeUnavailable()
            : ((bool) data_get($payload, 'probe_unavailable') || $reason === 'probe_unavailable');
        $probeHealthy ??= app(FastApiProbe::class)->healthy($target->probeOrigin());

        if ($unmonitored && ! $probeHealthy) {
            return $this->row('unmonitored', 'No monitoreado', 'amber', 'warning', 'La sonda de este origen no está chequeando. No es una falla del portal.');
        }

        if ($unmonitored && $probeHealthy) {
            $side = MonitorCopy::originLabel($target->probeOrigin());

            if ($target->last_ok === null) {
                return $this->row('pending', 'Pendiente', 'zinc', 'info', 'La sonda '.$side.' ya está arriba. Esperando el próximo sondeo.');
            }

            if ($target->last_ok === false) {
                return $this->row(
                    'down',
                    'DOWN',
                    'rose',
                    'critical',
                    'La sonda '.$side.' ya está arriba. Se conserva el último DOWN hasta el próximo ciclo.',
                );
            }

            return $this->row(
                'ok',
                'UP',
                'lime',
                'ok',
                'La sonda '.$side.' ya está arriba. El próximo ciclo actualizará este destino.',
            );
        }

        if ($target->last_ok === null) {
            return $this->row('pending', 'Pendiente', 'zinc', 'info', 'Aún no hay un sondeo.');
        }

        if ($target->last_ok === false) {
            return $this->row(
                'down',
                'DOWN',
                'rose',
                'critical',
                MonitorCopy::reason($reason),
                [['key' => $reason ?? 'down', 'text' => MonitorCopy::reason($reason)]],
            );
        }

        $issues = $this->degradationIssues($target, $recent, $payload);

        if ($issues === []) {
            return $this->row('ok', 'UP', 'lime', 'ok', MonitorCopy::reason($reason));
        }

        $critical = collect($issues)->contains(fn (array $issue): bool => ($issue['severity'] ?? '') === 'critical');

        return $this->row(
            'degraded',
            'Degradado',
            'amber',
            $critical ? 'critical' : 'warning',
            implode(' · ', array_column($issues, 'text')),
            $issues,
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
        if ($status !== null && $status >= 500) {
            $issues[] = ['key' => 'http_5xx', 'text' => 'HTTP '.$status.' con el host alcanzable', 'severity' => 'critical'];
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
     * @param  list<array{key: string, text: string, severity?: string}>  $issues
     * @return array<string, mixed>
     */
    private function row(string $key, string $label, string $tone, string $severity, string $detail, array $issues = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'tone' => $tone,
            'severity' => $severity,
            'detail' => $detail,
            'issues' => $issues,
            'slow' => collect($issues)->contains(fn (array $issue): bool => str_starts_with($issue['key'], 'ttfb_')),
        ];
    }
}
