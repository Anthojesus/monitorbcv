<?php

namespace App\Services\Monitoring;

use App\Models\MonitorCheck;
use App\Models\MonitorTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class FastApiProbe
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $runtimeCache = [];

    public function enabled(?string $origin = null): bool
    {
        if ($origin === null) {
            return $this->enabled(MonitorTarget::ORIGIN_INTERNAL) || $this->enabled(MonitorTarget::ORIGIN_EXTERNAL);
        }

        return (bool) $this->configFor($origin)['enabled'];
    }

    public function healthy(?string $origin = null): bool
    {
        $origin ??= MonitorTarget::ORIGIN_INTERNAL;

        return (bool) data_get($this->runtime($origin), 'api.ok');
    }

    /**
     * @return array<string, mixed>
     */
    public function runtime(?string $origin = null): array
    {
        if ($origin === null) {
            $internal = $this->runtime(MonitorTarget::ORIGIN_INTERNAL);
            $external = $this->runtime(MonitorTarget::ORIGIN_EXTERNAL);

            return [
                'internal' => $internal,
                'external' => $external,
                'engine' => $internal['engine'],
                'api' => $internal['api'],
                'python' => $internal['python'],
            ];
        }

        return $this->runtimeCache[$origin] ??= $this->cachedRuntime($origin);
    }

    /**
     * Pide /health a las sondas. Solo el cron debe llamarlo; la web nunca espera esto.
     *
     * @return array<string, mixed>
     */
    public function refreshRuntime(?string $origin = null): array
    {
        if ($origin === null) {
            $this->refreshRuntime(MonitorTarget::ORIGIN_INTERNAL);
            $this->refreshRuntime(MonitorTarget::ORIGIN_EXTERNAL);

            return $this->runtime();
        }

        $detected = $this->detectRuntime($origin);
        Cache::put($this->runtimeCacheKey($origin), $detected, max(5, (int) config('monitor.runtime_cache_seconds', 20)));
        $this->runtimeCache[$origin] = $detected;

        return $detected;
    }

    /**
     * @return array<string, mixed>
     */
    public function probe(MonitorTarget $target): array
    {
        $origin = $target->probeOrigin();
        $config = $this->configFor($origin);

        if ($config['url'] === '') {
            throw new ConnectionException('La sonda '.$origin.' no tiene URL configurada.');
        }

        $response = $this->client($config, $target->timeout_seconds + 5)
            ->post($config['url'].'/v1/checks', [
                'target' => [
                    'id' => $target->id,
                    'name' => $target->name,
                    'url' => $target->url,
                    'kind' => $target->kind,
                    'method' => $target->method,
                    'expected_status' => $target->expectedStatusCodes(),
                    'expected_keyword' => $target->expected_keyword,
                    'verify_ssl' => $target->verify_ssl,
                    'timeout_seconds' => $target->timeout_seconds,
                ],
            ]);

        $response->throw();

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }

    /**
     * @return array{ok: bool, hint: string, url: string, count: int, events: list<array<string, mixed>>, runtime: array<string, mixed>}
     */
    public function logs(string $origin): array
    {
        $config = $this->configFor($origin);
        $runtime = $this->runtime($origin);
        $base = [
            'ok' => false,
            'hint' => 'La sonda no está habilitada.',
            'url' => $config['url'],
            'count' => 0,
            'events' => [],
            'runtime' => $runtime,
        ];

        if (! $config['enabled'] || $config['url'] === '') {
            return $base;
        }

        try {
            $response = $this->client($config, 5)->get($config['url'].'/v1/logs');

            if ($response->status() === 404) {
                $base['hint'] = 'La sonda responde, pero aún no publica /v1/logs. Actualice main.py en ese host (guía de contingencia).';

                return $base;
            }

            if ($response->status() === 401) {
                $base['hint'] = 'La sonda rechazó el token Bearer. Compare MONITOR_API_*_TOKEN con MONITOR_API_TOKEN del host.';

                return $base;
            }

            $response->throw();

            $events = data_get($response->json(), 'events', []);

            return [
                'ok' => true,
                'hint' => 'GET '.$config['url'].'/v1/logs respondió OK.',
                'url' => $config['url'],
                'count' => (int) data_get($response->json(), 'count', is_array($events) ? count($events) : 0),
                'events' => is_array($events) ? array_values($events) : [],
                'runtime' => $runtime,
            ];
        } catch (ConnectionException|Throwable $exception) {
            $base['hint'] = 'No hay logs: '.$exception->getMessage();

            return $base;
        }
    }

    public function engineLabel(): string
    {
        return (string) data_get($this->runtime(MonitorTarget::ORIGIN_INTERNAL), 'engine', 'php-curl');
    }

    /**
     * @return array{url: string, token: string, enabled: bool, connect_timeout: float, health_timeout: float}
     */
    public function configFor(string $origin): array
    {
        $origin = $origin === MonitorTarget::ORIGIN_EXTERNAL
            ? MonitorTarget::ORIGIN_EXTERNAL
            : MonitorTarget::ORIGIN_INTERNAL;

        $probe = (array) config('monitor.probes.'.$origin, []);

        return [
            'url' => rtrim((string) ($probe['url'] ?? ''), '/'),
            'token' => (string) ($probe['token'] ?? ''),
            'enabled' => (bool) ($probe['enabled'] ?? false),
            'connect_timeout' => (float) config('monitor.fastapi.connect_timeout', 3),
            'health_timeout' => (float) config('monitor.fastapi.health_timeout', 8),
        ];
    }

    /**
     * @return array{
     *     engine: string,
     *     api: array{ok: bool, label: string, hint: string},
     *     python: array{ok: bool, label: string, hint: string}
     * }
     */
    /**
     * @return array{
     *     engine: string,
     *     api: array{ok: bool, label: string, hint: string},
     *     python: array{ok: bool, label: string, hint: string}
     * }
     */
    private function cachedRuntime(string $origin): array
    {
        $cached = Cache::get($this->runtimeCacheKey($origin));

        if (is_array($cached) && isset($cached['api'], $cached['python'], $cached['engine'])) {
            return $cached;
        }

        $config = $this->configFor($origin);

        if (! $config['enabled'] || $config['url'] === '') {
            return $this->detectRuntime($origin);
        }

        $label = $origin === MonitorTarget::ORIGIN_EXTERNAL ? 'Exterior' : 'Interior';

        return [
            'engine' => 'php-curl',
            'api' => [
                'ok' => false,
                'label' => 'API '.$label,
                'hint' => 'El estado de la sonda se actualiza en segundo plano. No bloquea esta pantalla.',
            ],
            'python' => [
                'ok' => false,
                'label' => 'Sonda Python '.$label,
                'hint' => 'El cron refresca /health. Esta página no espera a las sondas.',
            ],
        ];
    }

    private function runtimeCacheKey(string $origin): string
    {
        return 'monitor:probe-runtime:'.$origin;
    }

    /**
     * @return array{
     *     engine: string,
     *     api: array{ok: bool, label: string, hint: string},
     *     python: array{ok: bool, label: string, hint: string}
     * }
     */
    private function detectRuntime(string $origin): array
    {
        $config = $this->configFor($origin);
        $label = $origin === MonitorTarget::ORIGIN_EXTERNAL ? 'Exterior' : 'Interior';
        $api = [
            'ok' => false,
            'label' => 'API '.$label,
            'hint' => 'La sonda '.$label.' no está habilitada.',
        ];
        $python = [
            'ok' => false,
            'label' => 'Sonda Python '.$label,
            'hint' => 'El servicio Python '.$label.' no está habilitado.',
        ];

        if (! $config['enabled'] || $config['url'] === '') {
            return ['engine' => 'php-curl', 'api' => $api, 'python' => $python];
        }

        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
            $api['hint'] = 'La web no espera /health. El cron actualiza este semáforo.';
            $python['hint'] = 'Sondeo en segundo plano.';

            return ['engine' => 'php-curl', 'api' => $api, 'python' => $python];
        }

        try {
            $healthTimeout = max(2.0, (float) ($config['health_timeout'] ?? 8));
            $response = $this->healthClient($config, $healthTimeout)->get($config['url'].'/health');

            if ($response->successful()) {
                $payload = $response->json();
                $payload = is_array($payload) ? $payload : [];
                $probeValue = strtolower((string) ($payload['probe'] ?? $payload['status'] ?? 'ok'));
                $probeOk = in_array($probeValue, ['ok', 'up', 'healthy', 'ready'], true);
                $api = [
                    'ok' => true,
                    'label' => 'API '.$label,
                    'hint' => 'GET '.$config['url'].'/health respondió HTTP '.$response->status().'.',
                ];
                $python = [
                    'ok' => $probeOk,
                    'label' => 'Sonda Python '.$label,
                    'hint' => $probeOk
                        ? 'El proceso de probe.py ('.$label.') está vivo y listo para sondear.'
                        : 'La API '.$label.' responde, pero el módulo de sonda no reportó OK (probe='.($payload['probe'] ?? 'ausente').').',
                ];

                $latestEngine = data_get(
                    MonitorCheck::query()->latest('checked_at')->first()?->payload,
                    'engine',
                );

                if ($probeOk && $latestEngine === 'php-curl' && $origin === MonitorTarget::ORIGIN_INTERNAL) {
                    $python['hint'] = 'La API interior está UP, pero el último sondeo usó PHP (cURL).';
                }

                return [
                    'engine' => $probeOk ? 'fastapi' : 'php-curl',
                    'api' => $api,
                    'python' => $python,
                ];
            }

            $api['hint'] = 'La API '.$label.' en '.$config['url'].' respondió HTTP '.$response->status().'.';
            $python['hint'] = 'Sin /health no se puede confirmar probe.py ('.$label.').';
        } catch (ConnectionException|Throwable $exception) {
            $api['hint'] = 'No se pudo confirmar '.$config['url'].'/health: '.$exception->getMessage();
            $python['hint'] = 'El semáforo Exterior/Interior usa /health. Si el servicio está arriba, suele ser timeout TLS o red hasta el VPS.';
        }

        return ['engine' => 'php-curl', 'api' => $api, 'python' => $python];
    }

    /**
     * @param  array{url: string, token: string, enabled: bool, connect_timeout: float, health_timeout?: float}  $config
     */
    private function client(array $config, float $timeout): PendingRequest
    {
        $request = Http::connectTimeout(max(0.5, $config['connect_timeout']))->timeout($timeout);

        if ($config['token'] !== '') {
            $request = $request->withToken($config['token']);
        }

        return $request;
    }

    /**
     * /health es público. No manda Bearer y da más tiempo al TLS del VPS.
     *
     * @param  array{url: string, token: string, enabled: bool, connect_timeout: float, health_timeout?: float}  $config
     */
    private function healthClient(array $config, float $timeout): PendingRequest
    {
        $connect = max(2.0, (float) ($config['connect_timeout'] ?? 3));

        return Http::connectTimeout($connect)->timeout($timeout);
    }
}
