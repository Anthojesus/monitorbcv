<?php

namespace App\Services\Monitoring;

use App\Models\MonitorTarget;

class HealthPayloadAnalyzer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function enrich(MonitorTarget $target, array $payload): array
    {
        if ((bool) ($payload['probe_unavailable'] ?? false)) {
            return $payload;
        }

        $json = data_get($payload, 'http.json');
        $health = is_array($json) ? $this->parse($json) : null;
        $forced = $target->kind === 'health';

        if ($health === null) {
            if ($forced && (bool) ($payload['ok'] ?? false)) {
                $payload['ok'] = false;
                $payload['availability'] = [
                    'up' => false,
                    'reason' => 'health_invalid',
                ];
            }

            return $payload;
        }

        $payload['health'] = $health;
        $payload['type'] = 'health_check';

        if (! (bool) ($payload['ok'] ?? false)) {
            return $payload;
        }

        if (! $health['up']) {
            $payload['ok'] = false;
            $payload['availability'] = [
                'up' => false,
                'reason' => $health['down_count'] > 0 ? 'health_check_down' : 'health_down',
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>|null
     */
    public function parse(array $json): ?array
    {
        $known = $this->parseKnownFormat($json);

        if ($known !== null) {
            return $known;
        }

        foreach ($this->unwrapCandidates($json) as $inner) {
            $known = $this->parseKnownFormat($inner);

            if ($known !== null) {
                return $known;
            }
        }

        if (array_key_exists('success', $json)) {
            return $this->fromSuccessFlag($json['success']);
        }

        foreach ($this->unwrapCandidates($json) as $inner) {
            if (array_key_exists('success', $inner)) {
                return $this->fromSuccessFlag($inner['success']);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>|null
     */
    private function parseKnownFormat(array $json): ?array
    {
        if ($this->looksMicroprofile($json)) {
            return $this->fromChecks($json, 'microprofile');
        }

        if ($this->looksSpring($json)) {
            return $this->fromComponents($json);
        }

        if (array_key_exists('status', $json) || array_key_exists('healthy', $json) || array_key_exists('state', $json)) {
            $status = $json['status'] ?? $json['state'] ?? ($json['healthy'] === true ? 'UP' : 'DOWN');

            return [
                'format' => 'generic',
                'status' => $this->normalizeStatus($status),
                'up' => $this->isUp($status),
                'checks' => [],
                'up_count' => 0,
                'down_count' => 0,
                'unknown_count' => 0,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function unwrapCandidates(array $json): array
    {
        $candidates = [];

        foreach (['data', 'result', 'payload'] as $key) {
            $inner = $json[$key] ?? null;

            if (! is_array($inner) || $inner === [] || array_is_list($inner)) {
                continue;
            }

            $candidates[] = $inner;
        }

        return $candidates;
    }

    /**
     * @return array<string, mixed>
     */
    private function fromSuccessFlag(mixed $success): array
    {
        $up = $this->isUp($success);

        return [
            'format' => 'generic',
            'status' => $up ? 'UP' : 'DOWN',
            'up' => $up,
            'checks' => [],
            'up_count' => 0,
            'down_count' => 0,
            'unknown_count' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function looksMicroprofile(array $json): bool
    {
        return isset($json['checks']) && is_array($json['checks']);
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function looksSpring(array $json): bool
    {
        return isset($json['components']) && is_array($json['components']);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function fromChecks(array $json, string $format): array
    {
        $checks = [];

        foreach ($json['checks'] as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $checks[] = $this->normalizeCheck(
                (string) ($item['name'] ?? 'check-'.$index),
                $item['status'] ?? data_get($item, 'data.status'),
                is_array($item['data'] ?? null) ? $item['data'] : [],
            );
        }

        return $this->summarize($json['status'] ?? null, $checks, $format);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function fromComponents(array $json): array
    {
        $checks = [];

        foreach ($json['components'] as $name => $item) {
            if (! is_array($item)) {
                continue;
            }

            $checks[] = $this->normalizeCheck(
                (string) $name,
                $item['status'] ?? null,
                is_array($item['details'] ?? null) ? $item['details'] : [],
            );
        }

        return $this->summarize($json['status'] ?? null, $checks, 'spring');
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @return array<string, mixed>
     */
    private function summarize(mixed $status, array $checks, string $format): array
    {
        $down = collect($checks)->where('up', false)->count();
        $up = collect($checks)->where('up', true)->count();
        $unknown = count($checks) - $down - $up;
        $overall = $status !== null ? $this->isUp($status) : $down === 0;

        return [
            'format' => $format,
            'status' => $this->normalizeStatus($status ?? ($overall ? 'UP' : 'DOWN')),
            'up' => $overall && $down === 0,
            'checks' => $checks,
            'up_count' => $up,
            'down_count' => $down,
            'unknown_count' => $unknown,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeCheck(string $name, mixed $status, array $data): array
    {
        $normalized = $this->normalizeStatus($status);

        return [
            'name' => $name,
            'status' => $normalized,
            'up' => $status === null ? null : $this->isUp($status),
            'detail' => $this->detailLine($data),
            'data' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function detailLine(array $data): ?string
    {
        if ($data === []) {
            return null;
        }

        $parts = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            }

            $parts[] = $key.': '.$value;
        }

        return implode(' · ', $parts);
    }

    private function normalizeStatus(mixed $status): string
    {
        if ($status === null || $status === '') {
            return 'UNKNOWN';
        }

        if (is_bool($status)) {
            return $status ? 'UP' : 'DOWN';
        }

        return strtoupper(trim((string) $status));
    }

    private function isUp(mixed $status): bool
    {
        if (is_bool($status)) {
            return $status;
        }

        return in_array($this->normalizeStatus($status), ['UP', 'OK', 'ALIVE', 'HEALTHY', 'PASS', 'PASSED', 'TRUE', '1'], true);
    }
}
