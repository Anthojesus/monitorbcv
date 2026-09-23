<?php

namespace App\Services\Monitoring;

use App\Models\MonitorTarget;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class HttpProbe
{
    public function __construct(private SecurityHeaderAnalyzer $securityHeaders) {}

    /**
     * @return array<string, mixed>
     */
    public function probe(MonitorTarget $target): array
    {
        $startedAt = now();
        $checkId = (string) Str::uuid();
        $curl = [];
        $error = null;
        $response = null;

        $started = hrtime(true);
        $issuerFallback = false;

        try {
            $response = $this->send($target, $target->verify_ssl, $curl);
        } catch (RequestException|TransferException $exception) {
            $error = $this->normalizeError($exception);
        } catch (Throwable $exception) {
            $error = [
                'type' => class_basename($exception),
                'message' => $exception->getMessage(),
            ];
        }

        if (
            $error !== null
            && $target->verify_ssl
            && (bool) config('monitor.retry_untrusted_issuer')
            && ProbeErrorClassifier::isUntrustedIssuer($error)
        ) {
            try {
                $response = $this->send($target, false, $curl);
                $error = null;
                $issuerFallback = true;
            } catch (RequestException|TransferException $exception) {
                $error = $this->normalizeError($exception);
            } catch (Throwable $exception) {
                $error = [
                    'type' => class_basename($exception),
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $elapsedMs = (int) round((hrtime(true) - $started) / 1_000_000);
        $headers = $this->headers($response);
        $body = $response?->body() ?? '';
        $status = $response?->status();
        $timings = $this->timings($curl, $elapsedMs);
        $dns = $this->dns($target->url, $curl);
        $tls = $this->tls($curl, $target->url, $issuerFallback);
        $content = $this->content($body, $headers, $target);
        $availability = $this->availability($status, $target, $content, $error);

        $payload = [
            'schema_version' => (string) config('monitor.schema_version'),
            'type' => 'http_check',
            'check_id' => $checkId,
            'engine' => 'php-curl',
            'target' => [
                'id' => $target->id,
                'name' => $target->name,
                'url' => $target->url,
                'method' => $target->method,
                'expected_status' => $target->expectedStatusCodes(),
                'expected_keyword' => $target->expected_keyword,
                'kind' => $target->kind,
                'verify_ssl' => $target->verify_ssl,
                'timeout_seconds' => $target->timeout_seconds,
            ],
            'probe' => [
                'from_host' => gethostname() ?: null,
                'php_version' => PHP_VERSION,
                'user_agent' => config('monitor.user_agent'),
            ],
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
            'ok' => $availability['up'],
            'availability' => $availability,
            'request' => [
                'method' => $target->method,
                'url' => $target->url,
                'final_url' => $this->stringStat($curl, 'url') ?? $response?->effectiveUri()?->__toString(),
            ],
            'redirects' => $this->redirects($response, $curl),
            'dns' => $dns,
            'tcp' => [
                'remote_ip' => $this->stringStat($curl, 'primary_ip'),
                'remote_port' => $this->intStat($curl, 'primary_port'),
                'local_ip' => $this->stringStat($curl, 'local_ip'),
                'local_port' => $this->intStat($curl, 'local_port'),
                'connect_ms' => $timings['tcp'],
            ],
            'tls' => $tls,
            'http' => [
                'status' => $status,
                'reason' => $response?->reason(),
                'version' => $this->httpVersion($curl),
                'headers' => $headers,
                'content_type' => $headers['content-type'] ?? $this->stringStat($curl, 'content_type'),
                'content_encoding' => $headers['content-encoding'] ?? null,
                'content_length_header' => isset($headers['content-length']) ? (int) $headers['content-length'] : null,
                'body_bytes' => strlen($body),
                'header_bytes' => $this->intStat($curl, 'header_size'),
                'request_bytes' => $this->intStat($curl, 'request_size'),
                'body_sha256' => $body === '' ? null : hash('sha256', $body),
                'title' => $content['title'],
                'server' => $headers['server'] ?? null,
                'powered_by' => $headers['x-powered-by'] ?? null,
                'cookies_set' => $this->setCookieCount($headers),
                'json' => $this->jsonBody($body),
            ],
            'security' => $this->securityHeaders->analyze($headers),
            'cache' => [
                'cache_control' => $headers['cache-control'] ?? null,
                'etag' => $headers['etag'] ?? null,
                'expires' => $headers['expires'] ?? null,
                'age' => isset($headers['age']) ? (int) $headers['age'] : null,
                'last_modified' => $headers['last-modified'] ?? null,
                'pragma' => $headers['pragma'] ?? null,
            ],
            'timings_ms' => $timings,
            'performance' => [
                'ttfb_ms' => $timings['ttfb'],
                'total_ms' => $timings['total'],
                'download_speed_bps' => $this->intStat($curl, 'speed_download'),
                'size_download' => $this->intStat($curl, 'size_download'),
            ],
            'content_check' => $content['check'],
            'error' => $error,
        ];

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $curl
     * @return array<string, int|null>
     */
    private function timings(array $curl, int $fallbackTotal): array
    {
        $lookup = $this->ms($curl, 'namelookup_time');
        $connect = $this->ms($curl, 'connect_time');
        $appconnect = $this->ms($curl, 'appconnect_time');
        $starttransfer = $this->ms($curl, 'starttransfer_time');
        $total = $this->ms($curl, 'total_time') ?? $fallbackTotal;
        $redirect = $this->ms($curl, 'redirect_time');

        $tcp = ($connect !== null && $lookup !== null) ? max(0, $connect - $lookup) : null;
        $tls = ($appconnect !== null && $connect !== null && $appconnect > $connect)
            ? max(0, $appconnect - $connect)
            : null;
        $ttfb = $starttransfer;
        $download = $starttransfer !== null ? max(0, $total - $starttransfer) : null;

        return [
            'dns' => $lookup,
            'tcp' => $tcp,
            'tls' => $tls,
            'ttfb' => $ttfb,
            'download' => $download,
            'redirect' => $redirect,
            'total' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $curl
     * @return array<string, mixed>
     */
    private function dns(string $url, array $curl): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? $host : null;
        $primary = $this->stringStat($curl, 'primary_ip');

        return [
            'hostname' => $host,
            'resolved_ips' => $primary !== null && $primary !== '' ? [$primary] : [],
            'primary_ip' => $primary,
            'records' => [],
            'time_ms' => $this->ms($curl, 'namelookup_time'),
        ];
    }

    /**
     * @param  array<string, mixed>  $curl
     */
    private function send(MonitorTarget $target, bool $verify, array &$curl): Response
    {
        $curlOptions = [
            CURLOPT_CERTINFO => true,
        ];

        return Http::timeout($target->timeout_seconds)
            ->connectTimeout(min(10, $target->timeout_seconds))
            ->withOptions([
                'http_errors' => false,
                'verify' => $verify,
                'allow_redirects' => [
                    'max' => 10,
                    'track_redirects' => true,
                ],
                'curl' => $curlOptions,
                'on_stats' => function (TransferStats $stats) use (&$curl): void {
                    $curl = $stats->getHandlerStats();
                },
            ])
            ->withHeaders([
                'User-Agent' => (string) config('monitor.user_agent'),
                'Accept' => $target->isHealth()
                    ? 'application/json, */*;q=0.8'
                    : 'text/html,application/json;q=0.9,*/*;q=0.8',
                'Accept-Encoding' => 'gzip, deflate, br',
            ])
            ->send($target->method, $target->url);
    }

    private function tls(array $curl, string $url, bool $issuerFallback = false): array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $certinfo = $curl['certinfo'] ?? [];
        $leaf = is_array($certinfo) && $certinfo !== [] ? ($certinfo[0] ?? []) : [];

        $notAfter = $this->parseCertDate(is_array($leaf) ? ($leaf['Expire date'] ?? null) : null);
        $notBefore = $this->parseCertDate(is_array($leaf) ? ($leaf['Start date'] ?? null) : null);
        $days = $notAfter?->diffInDays(now(), false);
        $daysRemaining = $days === null ? null : (int) round(-1 * $days);
        $verified = ! $issuerFallback && ($this->intStat($curl, 'ssl_verify_result') ?? 1) === 0;

        return [
            'used' => $scheme === 'https',
            'verify_result' => $this->intStat($curl, 'ssl_verify_result'),
            'verified' => $verified,
            'trust' => $issuerFallback ? 'issuer_untrusted' : ($verified ? 'os' : 'unverified'),
            'certificate' => [
                'subject' => is_array($leaf) ? ($leaf['Subject'] ?? null) : null,
                'issuer' => is_array($leaf) ? ($leaf['Issuer'] ?? null) : null,
                'not_before' => $notBefore?->toIso8601String(),
                'not_after' => $notAfter?->toIso8601String(),
                'days_remaining' => $daysRemaining,
                'expiring_soon' => $daysRemaining !== null && $daysRemaining <= 21,
                'serial' => is_array($leaf) ? ($leaf['Serial Number'] ?? null) : null,
                'signature' => is_array($leaf) ? ($leaf['Signature Algorithm'] ?? null) : null,
                'public_key' => is_array($leaf) ? ($leaf['Public Key Algorithm'] ?? null) : null,
                'chain_length' => is_array($certinfo) ? count($certinfo) : 0,
            ],
            'handshake_ms' => $this->timings($curl, 0)['tls'],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{title: string|null, check: array<string, mixed>}
     */
    private function content(string $body, array $headers, MonitorTarget $target): array
    {
        $title = null;

        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $matches) === 1) {
            $title = html_entity_decode(trim(strip_tags($matches[1])), ENT_QUOTES | ENT_HTML5);
        }

        $keyword = $target->expected_keyword;
        $found = $keyword === null ? null : str_contains($body, $keyword);

        return [
            'title' => $title,
            'check' => [
                'keyword' => $keyword,
                'keyword_found' => $found,
                'is_html' => str_contains(strtolower($headers['content-type'] ?? ''), 'text/html'),
                'is_json' => str_contains(strtolower($headers['content-type'] ?? ''), 'json'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>|null  $error
     * @return array<string, mixed>
     */
    private function availability(?int $status, MonitorTarget $target, array $content, ?array $error): array
    {
        if ($error !== null) {
            return ['up' => false, 'reason' => ProbeErrorClassifier::reason($error) ?? 'transport'];
        }

        if ($status === null) {
            return ['up' => false, 'reason' => 'no_response'];
        }

        if (! $target->acceptsHttpStatus($status)) {
            return ['up' => false, 'reason' => 'unexpected_status'];
        }

        if (($content['check']['keyword_found'] ?? null) === false) {
            return ['up' => false, 'reason' => 'keyword_missing'];
        }

        return ['up' => true, 'reason' => $target->httpSuccessReason($status)];
    }

    /**
     * @param  array<string, mixed>  $curl
     * @return array{count: int, time_ms: int|null, hops: list<array{url: string, status: int|null}>}
     */
    private function redirects(?Response $response, array $curl): array
    {
        $history = $response?->header('X-Guzzle-Redirect-History');
        $statusHistory = $response?->header('X-Guzzle-Redirect-Status-History');

        $urls = $history ? array_map(trim(...), explode(',', $history)) : [];
        $codes = $statusHistory ? array_map(intval(...), explode(',', $statusHistory)) : [];

        $hops = [];

        foreach ($urls as $index => $url) {
            $hops[] = [
                'url' => $url,
                'status' => $codes[$index] ?? null,
            ];
        }

        return [
            'count' => $this->intStat($curl, 'redirect_count') ?? count($hops),
            'time_ms' => $this->ms($curl, 'redirect_time'),
            'hops' => $hops,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(?Response $response): array
    {
        if ($response === null) {
            return [];
        }

        $headers = [];

        foreach ($response->headers() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        unset($headers['x-guzzle-redirect-history'], $headers['x-guzzle-redirect-status-history']);

        return $headers;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function setCookieCount(array $headers): int
    {
        $value = $headers['set-cookie'] ?? '';

        return $value === '' ? 0 : count(array_filter(explode(',', $value)));
    }

    /**
     * @param  array<string, mixed>  $curl
     */
    private function httpVersion(array $curl): ?string
    {
        $version = $this->intStat($curl, 'http_version');

        return match ($version) {
            1 => 'HTTP/1.0',
            2 => 'HTTP/1.1',
            3 => 'HTTP/2',
            30 => 'HTTP/3',
            default => $version !== null ? (string) $version : null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonBody(string $body): ?array
    {
        if ($body === '' || strlen($body) > 65536) {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeError(Throwable $exception): array
    {
        return [
            'type' => class_basename($exception),
            'message' => $exception->getMessage(),
        ];
    }

    /**
     * @param  array<string, mixed>  $curl
     */
    private function ms(array $curl, string $key): ?int
    {
        if (! isset($curl[$key]) || ! is_numeric($curl[$key])) {
            return null;
        }

        return (int) round(((float) $curl[$key]) * 1000);
    }

    /**
     * @param  array<string, mixed>  $curl
     */
    private function stringStat(array $curl, string $key): ?string
    {
        $value = $curl[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $curl
     */
    private function intStat(array $curl, string $key): ?int
    {
        $value = $curl[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function parseCertDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
