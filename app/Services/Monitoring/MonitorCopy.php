<?php

namespace App\Services\Monitoring;

use App\Models\MonitorCheck;
use Illuminate\Support\Collection;

class MonitorCopy
{
    public static function reason(?string $reason): string
    {
        return match ($reason) {
            'ok' => 'Respuesta válida',
            'tls' => 'Fallo de certificado TLS',
            'transport' => 'Fallo de transporte',
            'timeout' => 'Se agotó el tiempo de espera',
            'dns' => 'No resolvió el DNS',
            'tcp' => 'No estableció TCP',
            'no_response' => 'Sin respuesta HTTP',
            'unexpected_status' => 'Código HTTP fuera de lo esperado',
            'http_reachable' => 'El servidor responde',
            'keyword_missing' => 'No apareció la palabra clave',
            'health_down' => 'El health global está DOWN',
            'health_check_down' => 'Una dependencia del health está DOWN',
            'health_invalid' => 'El JSON de health no es válido',
            'probe_unavailable' => 'No se está monitoreando',
            default => $reason ?: 'Sin motivo aún',
        };
    }

    /**
     * Texto largo para que el operador sepa qué pasó y qué puede cambiar.
     */
    public static function reasonHint(?string $reason, ?int $status = null, ?string $origin = null): ?string
    {
        return match ($reason) {
            'http_reachable' => self::reachableHint($status),
            'unexpected_status' => $status !== null
                ? "Llegó HTTP {$status}, pero este sitio solo acepta los códigos que configuraste. El host respondió; si ese código debe ser UP, añádelo a la lista o usa «El servidor responde»."
                : 'Llegó una respuesta HTTP que no está en la lista de códigos aceptados.',
            'probe_unavailable' => 'No se está monitoreando: el servicio '.self::originLabel($origin).' (sonda FastAPI) está caído o apagado. No es una falla del portal. El último UP/DOWN se conserva.',
            default => null,
        };
    }

    public static function originLabel(?string $origin): string
    {
        return $origin === 'external' ? 'Exterior' : 'Interior';
    }

    private static function reachableHint(?int $status): string
    {
        if ($status !== null && $status >= 500) {
            return "El host contestó HTTP {$status}: la máquina está alcanzable, no es un corte de red. El verde no significa que la página funcione; un 5xx es fallo de la aplicación. Si un error de servidor debe ser DOWN, edita el sitio y elige «Solo códigos HTTP esperados».";
        }

        if ($status !== null && $status >= 400) {
            return "El host contestó HTTP {$status}: hay respuesta, no un corte de red. El verde solo indica que el servidor contestó. Si ese código no debe ser UP, edita el sitio y elige «Solo códigos HTTP esperados».";
        }

        return 'El criterio de este sitio es «el servidor responde»: cualquier HTTP cuenta como UP, aunque no sea 200. Si solo quieres verde con códigos concretos, edita el sitio y cambia el criterio.';
    }

    public static function text(mixed $value, string $fallback = '—'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        if (is_bool($value)) {
            return $value ? 'sí' : 'no';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (! is_array($value)) {
            return $fallback;
        }

        $distinguished = self::distinguishedName($value);

        if ($distinguished !== null) {
            return $distinguished;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) && $encoded !== '' ? $encoded : $fallback;
    }

    /**
     * @param  array<int|string, mixed>  $value
     */
    private static function distinguishedName(array $value): ?string
    {
        $parts = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                return null;
            }

            $pairs = isset($entry[0]) && is_array($entry[0]) ? $entry : [$entry];

            foreach ($pairs as $pair) {
                if (! is_array($pair) || ! isset($pair[1]) || ! is_scalar($pair[1])) {
                    return null;
                }

                $parts[] = (string) $pair[1];
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * Servidor HTTP anunciado en Server / X-Powered-By / Via.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{family: string, product: string, raw: string|null, known: bool}
     */
    public static function webServer(?array $payload): array
    {
        $http = data_get($payload ?? [], 'http');
        $http = is_array($http) ? $http : [];
        $headers = data_get($http, 'headers');
        $headers = is_array($headers) ? $headers : [];

        $raw = self::firstHeader($http['server'] ?? null, $headers['server'] ?? null);
        $powered = self::firstHeader($http['powered_by'] ?? null, $headers['x-powered-by'] ?? null);
        $via = self::firstHeader($headers['via'] ?? null);
        $haystack = strtolower(trim(implode(' ', array_filter([$raw, $powered, $via]))));

        $family = match (true) {
            $haystack === '' => null,
            str_contains($haystack, 'openresty') => 'OpenResty',
            str_contains($haystack, 'nginx') => 'Nginx',
            str_contains($haystack, 'apache-coyote') || str_contains($haystack, 'tomcat') => 'Apache Tomcat',
            str_contains($haystack, 'httpd') || str_contains($haystack, 'apache') => 'Apache2',
            str_contains($haystack, 'microsoft-iis') || str_contains($haystack, 'iis/') => 'IIS',
            str_contains($haystack, 'litespeed') => 'LiteSpeed',
            str_contains($haystack, 'caddy') => 'Caddy',
            str_contains($haystack, 'lighttpd') => 'lighttpd',
            str_contains($haystack, 'jetty') => 'Jetty',
            str_contains($haystack, 'wildfly') || str_contains($haystack, 'jboss') || str_contains($haystack, 'undertow') => 'WildFly',
            str_contains($haystack, 'kestrel') => 'Kestrel',
            str_contains($haystack, 'gunicorn') => 'Gunicorn',
            str_contains($haystack, 'uvicorn') => 'Uvicorn',
            str_contains($haystack, 'werkzeug') => 'Werkzeug',
            str_contains($haystack, 'express') => 'Express',
            str_contains($haystack, 'cloudflare') => 'Cloudflare',
            str_contains($haystack, 'varnish') => 'Varnish',
            str_contains($haystack, 'haproxy') => 'HAProxy',
            str_contains($haystack, 'traefik') => 'Traefik',
            str_contains($haystack, 'envoy') => 'Envoy',
            str_contains($haystack, 'bigip') || str_contains($haystack, 'big-ip') => 'F5 BIG-IP',
            default => null,
        };

        if ($family === null && is_string($raw) && $raw !== '') {
            return [
                'family' => strtok($raw, '/ ') ?: $raw,
                'product' => $raw,
                'raw' => $raw,
                'known' => false,
            ];
        }

        if ($family === null) {
            return [
                'family' => 'Sin dato',
                'product' => 'El sondeo no trajo cabecera Server',
                'raw' => null,
                'known' => false,
            ];
        }

        return [
            'family' => $family,
            'product' => $raw ?: ($powered ?: $family),
            'raw' => $raw,
            'known' => true,
        ];
    }

    private static function firstHeader(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * DNS + TCP: camino de red hasta el host, sin contar la app ni la descarga.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{ms: int|null, dns: int|null, tcp: int|null, label: string, hint: string}
     */
    public static function network(?array $payload): array
    {
        $dns = self::nullableInt(data_get($payload ?? [], 'timings_ms.dns'));
        $tcp = self::nullableInt(data_get($payload ?? [], 'timings_ms.tcp'));
        $ms = ($dns === null && $tcp === null) ? null : ($dns ?? 0) + ($tcp ?? 0);

        $label = match (true) {
            $ms === null => 'Sin dato',
            $ms <= 100 => 'Red rápida',
            $ms <= 250 => 'Red aceptable',
            default => 'Red lenta',
        };

        $hint = match (true) {
            $ms === null => 'Este sondeo no midió DNS/TCP. El próximo ciclo lo llena.',
            default => 'DNS '.($dns ?? 0).' ms + conexión TCP '.($tcp ?? 0).' ms. No incluye TLS ni el tiempo de la aplicación.',
        };

        return [
            'ms' => $ms,
            'dns' => $dns,
            'tcp' => $tcp,
            'label' => $label,
            'hint' => $hint,
        ];
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    public static function reliabilityLabel(int $percent, int $total): string
    {
        if ($total === 0) {
            return 'Sin historial';
        }

        return match (true) {
            $percent >= 95 => 'Estable',
            $percent >= 70 => 'Inestable',
            default => 'Intermitente',
        };
    }

    /**
     * @param  Collection<int, MonitorCheck>  $recent
     * @return array<string, mixed>
     */
    public static function reliability(Collection $recent, ?bool $currentOk): array
    {
        $recent = $recent->reject(
            fn (MonitorCheck $check): bool => $check->isProbeUnavailable(),
        )->values();
        $total = $recent->count();
        $ok = $recent->where('ok', true)->count();
        $failed = $total - $ok;
        $percent = $total === 0 ? 0 : (int) round(100 * $ok / $total);
        $oldest = $recent->last()?->checked_at;
        $newest = $recent->first()?->checked_at;
        $minutes = ($oldest !== null && $newest !== null)
            ? max(1, (int) $oldest->diffInMinutes($newest) ?: 1)
            : null;

        $streak = 0;
        foreach ($recent as $check) {
            if (! $check->ok) {
                break;
            }
            $streak++;
        }

        $lastFail = $recent->first(fn (MonitorCheck $check) => ! $check->ok);

        return [
            'percent' => $percent,
            'ok_count' => $ok,
            'fail_count' => $failed,
            'sample_size' => $total,
            'label' => self::reliabilityLabel($percent, $total),
            'window' => $minutes === null ? null : ($minutes < 60 ? "últimos {$minutes} min" : 'últimas '.max(1, (int) round($minutes / 60)).' h'),
            'ok_streak' => $streak,
            'last_fail_reason' => $lastFail?->availability_reason,
            'hint' => self::hint($percent, $ok, $total, $currentOk, $streak, $failed),
        ];
    }

    private static function hint(int $percent, int $ok, int $total, ?bool $currentOk, int $streak, int $failed): string
    {
        if ($total === 0) {
            return 'Todavía no hay muestras. El próximo ciclo llena esta ventana.';
        }

        if ($currentOk === true && $failed > 0) {
            return "Ahora UP. {$ok} de {$total} OK; el % baja por {$failed} fallos previos, no por el último chequeo.";
        }

        if ($currentOk === false) {
            return "Último chequeo falló. {$ok} de {$total} OK en la ventana. Revisa el motivo para decidir.";
        }

        if ($percent >= 95) {
            return "{$ok} de {$total} OK. Rachas de {$streak} aciertos seguidos: el destino se comporta estable.";
        }

        return "{$ok} de {$total} chequeos OK en esta ventana.";
    }
}
