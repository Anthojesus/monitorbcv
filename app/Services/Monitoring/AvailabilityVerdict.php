<?php

namespace App\Services\Monitoring;

use App\Models\MonitorTarget;

class AvailabilityVerdict
{
    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>|null  $error
     * @return array{up: bool, reason: string, host_ok: bool, page_ok: bool}
     */
    public function evaluate(MonitorTarget $target, ?int $status, array $content, ?array $error): array
    {
        if ($error !== null) {
            return $this->row(false, ProbeErrorClassifier::reason($error) ?? 'transport', false, false);
        }

        if ($status === null) {
            return $this->row(false, 'no_response', false, false);
        }

        $hostOk = $status >= 100 && $status <= 599;
        $pageOk = $this->pageOk($target, $status);

        if (! $target->acceptsHttpStatus($status)) {
            return $this->row(false, 'unexpected_status', $hostOk, false);
        }

        if (($content['check']['keyword_found'] ?? null) === false) {
            return $this->row(false, 'keyword_missing', $hostOk, false);
        }

        if ($this->pageIncident($target, $status)) {
            return $this->row(false, 'http_5xx', true, false);
        }

        return $this->row(true, $target->httpSuccessReason($status), $hostOk, $pageOk);
    }

    /**
     * Unifica la política PHP y FastAPI: el 5xx de un portal no puede quedar UP.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(MonitorTarget $target, array $payload): array
    {
        $availability = is_array($payload['availability'] ?? null) ? $payload['availability'] : [];

        if ((bool) ($payload['probe_unavailable'] ?? false)) {
            $payload['availability'] = $availability + [
                'host_ok' => false,
                'page_ok' => false,
            ];

            return $payload;
        }

        $status = data_get($payload, 'http.status');
        $status = is_numeric($status) ? (int) $status : null;
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : null;
        $content = [
            'check' => is_array($payload['content_check'] ?? null) ? $payload['content_check'] : [],
        ];

        $verdict = $this->evaluate($target, $status, $content, $error);
        $existing = is_string($availability['reason'] ?? null) ? $availability['reason'] : null;

        if (in_array($existing, ['health_down', 'health_check_down', 'health_invalid'], true)) {
            $payload['ok'] = false;
            $payload['availability'] = [
                'up' => false,
                'reason' => $existing,
                'host_ok' => $verdict['host_ok'],
                'page_ok' => false,
            ];

            return $payload;
        }

        $payload['ok'] = $verdict['up'];
        $payload['availability'] = $verdict;

        return $payload;
    }

    public function pageIncident(MonitorTarget $target, ?int $status): bool
    {
        return ! $target->isProxy() && $status !== null && $status >= 500;
    }

    public function pageOk(MonitorTarget $target, ?int $status): bool
    {
        if ($status === null || $status < 200 || $status >= 400) {
            return false;
        }

        if ($target->acceptsAnyHttpStatus()) {
            return true;
        }

        return $target->acceptsHttpStatus($status);
    }

    /**
     * @return array{up: bool, reason: string, host_ok: bool, page_ok: bool}
     */
    private function row(bool $up, string $reason, bool $hostOk, bool $pageOk): array
    {
        return [
            'up' => $up,
            'reason' => $reason,
            'host_ok' => $hostOk,
            'page_ok' => $pageOk,
        ];
    }
}
