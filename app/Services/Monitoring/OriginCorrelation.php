<?php

namespace App\Services\Monitoring;

use App\Models\MonitorTarget;
use Illuminate\Support\Collection;

class OriginCorrelation
{
    public static function normalizeUrl(string $url): string
    {
        $parts = parse_url(strtolower(trim($url)));
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$port.$path.$query;
    }

    /**
     * @param  Collection<int, MonitorTarget>  $targets
     * @param  array<int, array<string, mixed>>  $conditions  keyed by target id
     * @return list<array<string, mixed>>
     */
    public function diagnoses(Collection $targets, array $conditions): array
    {
        $rows = [];

        foreach ($targets->groupBy(fn (MonitorTarget $target): string => self::normalizeUrl($target->url)) as $url => $group) {
            $internal = $group->first(fn (MonitorTarget $target): bool => $target->probeOrigin() === MonitorTarget::ORIGIN_INTERNAL);
            $external = $group->first(fn (MonitorTarget $target): bool => $target->probeOrigin() === MonitorTarget::ORIGIN_EXTERNAL);

            if ($internal === null || $external === null) {
                continue;
            }

            $int = $conditions[$internal->id] ?? null;
            $ext = $conditions[$external->id] ?? null;

            if (! is_array($int) || ! is_array($ext)) {
                continue;
            }

            $row = $this->diagnosePair($url, $internal, $external, $int, $ext);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $internal
     * @param  array<string, mixed>  $external
     * @return array<string, mixed>|null
     */
    public function forPair(MonitorTarget $left, MonitorTarget $right, array $internal, array $external): ?array
    {
        $intTarget = $left->probeOrigin() === MonitorTarget::ORIGIN_INTERNAL ? $left : $right;
        $extTarget = $left->probeOrigin() === MonitorTarget::ORIGIN_EXTERNAL ? $left : $right;
        $int = $intTarget->is($left) ? $internal : $external;
        $ext = $extTarget->is($left) ? $internal : $external;

        if ($intTarget->probeOrigin() === $extTarget->probeOrigin()) {
            return null;
        }

        return $this->diagnosePair(self::normalizeUrl($left->url), $intTarget, $extTarget, $int, $ext);
    }

    /**
     * @param  array<string, mixed>  $int
     * @param  array<string, mixed>  $ext
     * @return array<string, mixed>|null
     */
    private function diagnosePair(string $url, MonitorTarget $internal, MonitorTarget $external, array $int, array $ext): ?array
    {
        $intKey = $int['key'] ?? '';
        $extKey = $ext['key'] ?? '';

        if (in_array($intKey, ['pending', ''], true) || in_array($extKey, ['pending', ''], true)) {
            return null;
        }

        $diagnosis = match (true) {
            $intKey === 'unmonitored' && $extKey === 'unmonitored' => [
                'key' => 'probes_down',
                'tone' => 'amber',
                'title' => 'Ambas sondas caídas',
                'detail' => 'No se está midiendo este URL por Interior ni por Exterior.',
            ],
            $intKey === 'unmonitored' => [
                'key' => 'internal_probe',
                'tone' => 'amber',
                'title' => 'Interior no monitoreado',
                'detail' => 'La sonda Interior está caída. El Exterior sí chequea; no compares latencias.',
            ],
            $extKey === 'unmonitored' => [
                'key' => 'external_probe',
                'tone' => 'amber',
                'title' => 'Exterior no monitoreado',
                'detail' => 'La sonda Exterior está caída. El Interior sí chequea; no compares latencias.',
            ],
            $extKey === 'down' && $intKey === 'ok' => [
                'key' => 'outside_only',
                'tone' => 'rose',
                'title' => 'Caído solo afuera',
                'detail' => 'Interior UP y Exterior DOWN: firewall, DNS, WAF o proxy. La app responde en la red BCV.',
            ],
            $extKey === 'down' && $intKey === 'down' => [
                'key' => 'app_down',
                'tone' => 'rose',
                'title' => 'App o servicio caído',
                'detail' => 'Interior y Exterior DOWN: el fallo está en el portal o en su servidor, no solo en Internet.',
            ],
            $extKey === 'down' && $intKey === 'degraded' => [
                'key' => 'app_down',
                'tone' => 'rose',
                'title' => 'App o servicio caído',
                'detail' => 'Exterior DOWN e Interior degradado: prioriza el portal, no solo la red pública.',
            ],
            ($ext['slow'] ?? false) && ($int['slow'] ?? false) => [
                'key' => 'bottleneck',
                'tone' => 'amber',
                'title' => 'Lento adentro y afuera',
                'detail' => 'Cuello de botella real (app, CPU o base). No es solo la red pública.',
            ],
            ($ext['slow'] ?? false) && $intKey === 'ok' => [
                'key' => 'outside_slow',
                'tone' => 'amber',
                'title' => 'Solo afuera lento',
                'detail' => 'Interior sano y Exterior lento: red, ISP, CDN o proxy.',
            ],
            default => null,
        };

        if ($diagnosis === null) {
            return null;
        }

        return $diagnosis + [
            'url' => $url,
            'internal_id' => $internal->id,
            'external_id' => $external->id,
            'internal_name' => $internal->name,
            'external_name' => $external->name,
        ];
    }
}
