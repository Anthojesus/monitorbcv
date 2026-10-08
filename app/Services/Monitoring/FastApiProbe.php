<?php

namespace App\Services\Monitoring;

use App\Models\MonitorCheck;
use App\Models\MonitorTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class FastApiProbe
{
    /**
     * La lectura sobrevive un cron de un minuto y un monitor:run largo.
     * Un TTL corto hacía que el dashboard pintara DOWN al expirar la clave.
     */
    private const RUNTIME_TTL_SECONDS = 86400;

    /**
     * Por debajo de esto, un cron cada minuto vuelve a marcar las sondas caídas entre corridas.
     */
    private const RUNTIME_STALE_FLOOR_SECONDS = 180;

    private const FAILURE_CONFIRMATIONS = 2;

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

        if (! $this->checksHealthFromThisProcess()) {
            return $this->runtime($origin);
        }

        $stored = $this->stabilize(
            $this->detectRuntime($origin),
            $this->storedRuntime($origin),
        );
        Cache::put($this->runtimeCacheKey($origin), $stored, $this->runtimeTtl());
        unset($this->runtimeCache[$origin]);

        return $this->runtime($origin);
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
     * @return array<string, mixed>
     */
    private function cachedRuntime(string $origin): array
    {
        $cached = $this->storedRuntime($origin);

        if ($cached !== null) {
            return $this->present($origin, $cached);
        }

        $config = $this->configFor($origin);

        if (! $config['enabled'] || $config['url'] === '') {
            return $this->detectRuntime($origin);
        }

        return $this->pendingRuntime($origin);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storedRuntime(string $origin): ?array
    {
        $cached = Cache::get($this->runtimeCacheKey($origin));

        if (! is_array($cached) || ! isset($cached['api'], $cached['python'], $cached['engine'])) {
            return null;
        }

        return $cached;
    }

    /**
     * Una falla aislada no pisa un OK reciente. La caída se publica al confirmarse.
     *
     * @param  array<string, mixed>  $detected
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    private function stabilize(array $detected, ?array $previous): array
    {
        $confirmations = $this->failureConfirmations();
        $detected['pending'] = false;
        $detected['stale'] = false;
        $detected['checked_at'] = now()->toIso8601String();
        $detected['api']['pending'] = false;
        $detected['python']['pending'] = false;

        $previousFresh = is_array($previous) && $this->sampleIsFresh($previous);

        foreach (['api', 'python'] as $part) {
            $streakKey = $part.'_failure_streak';
            $nowOk = (bool) ($detected[$part]['ok'] ?? false);

            if ($nowOk || ! $previousFresh) {
                $detected[$streakKey] = 0;

                continue;
            }

            $wasOk = (bool) data_get($previous, $part.'.ok', false);

            if (! $wasOk) {
                $detected[$streakKey] = $confirmations;

                continue;
            }

            $streak = (int) data_get($previous, $streakKey, 0) + 1;
            $detected[$streakKey] = $streak;

            if ($streak < $confirmations) {
                $detected[$part]['ok'] = true;
                $failureHint = (string) ($detected[$part]['hint'] ?? '');
                $detected[$part]['hint'] = trim('Un /health falló. Se mantiene el último OK hasta el próximo ciclo. '.$failureHint);
            }
        }

        $held = ($detected['api_failure_streak'] > 0 && $detected['api']['ok'])
            || ($detected['python_failure_streak'] > 0 && $detected['python']['ok']);

        if ($held && is_array($previous) && isset($previous['engine'])) {
            $detected['engine'] = $previous['engine'];
        }

        return $detected;
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @return array<string, mixed>
     */
    private function present(string $origin, array $runtime): array
    {
        $pending = (bool) ($runtime['pending'] ?? false);
        $runtime['pending'] = $pending;
        $runtime['api']['pending'] = (bool) ($runtime['api']['pending'] ?? false);
        $runtime['python']['pending'] = (bool) ($runtime['python']['pending'] ?? false);
        $runtime['stale'] = false;

        if ($pending) {
            return $runtime;
        }

        $age = $this->ageSeconds(isset($runtime['checked_at']) && is_string($runtime['checked_at']) ? $runtime['checked_at'] : null);

        if ($age === null || $age <= $this->staleAfterSeconds()) {
            return $runtime;
        }

        $label = $this->originLabel($origin);
        $minutes = max(1, intdiv($age, 60));
        $hint = 'Sin lectura nueva de /health desde hace '.$minutes.' min. El cron no está refrescando el semáforo '.$label.'.';
        $runtime['stale'] = true;
        $runtime['api']['ok'] = false;
        $runtime['api']['pending'] = false;
        $runtime['api']['hint'] = $hint;
        $runtime['python']['ok'] = false;
        $runtime['python']['pending'] = false;
        $runtime['python']['hint'] = $hint;

        return $runtime;
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingRuntime(string $origin): array
    {
        $label = $this->originLabel($origin);
        $hint = 'Aún no hay una lectura de /health. El próximo ciclo del cron la escribe.';

        return [
            'engine' => 'php-curl',
            'pending' => true,
            'stale' => false,
            'checked_at' => null,
            'api' => [
                'ok' => false,
                'pending' => true,
                'label' => 'API '.$label,
                'hint' => $hint,
            ],
            'python' => [
                'ok' => false,
                'pending' => true,
                'label' => 'Sonda Python '.$label,
                'hint' => $hint,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $sample
     */
    private function sampleIsFresh(array $sample): bool
    {
        if (! isset($sample['api'], $sample['python']) || ($sample['pending'] ?? false)) {
            return false;
        }

        $age = $this->ageSeconds(isset($sample['checked_at']) && is_string($sample['checked_at']) ? $sample['checked_at'] : null);

        if ($age === null) {
            return true;
        }

        return $age <= $this->staleAfterSeconds();
    }

    private function ageSeconds(?string $checkedAt): ?int
    {
        if ($checkedAt === null || $checkedAt === '') {
            return null;
        }

        try {
            $checked = Carbon::parse($checkedAt);
        } catch (Throwable) {
            return null;
        }

        return now()->getTimestamp() - $checked->getTimestamp();
    }

    private function runtimeTtl(): int
    {
        return max(
            self::RUNTIME_TTL_SECONDS,
            $this->staleAfterSeconds() + 60,
            (int) config('monitor.runtime_cache_seconds', self::RUNTIME_TTL_SECONDS),
        );
    }

    private function staleAfterSeconds(): int
    {
        return max(
            self::RUNTIME_STALE_FLOOR_SECONDS,
            (int) config('monitor.runtime_stale_seconds', 900),
        );
    }

    private function failureConfirmations(): int
    {
        return max(self::FAILURE_CONFIRMATIONS, (int) config('monitor.runtime_failure_confirmations', self::FAILURE_CONFIRMATIONS));
    }

    private function checksHealthFromThisProcess(): bool
    {
        return in_array(PHP_SAPI, ['cli', 'phpdbg'], true);
    }

    private function originLabel(string $origin): string
    {
        return $origin === MonitorTarget::ORIGIN_EXTERNAL ? 'Exterior' : 'Interior';
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
        $label = $this->originLabel($origin);
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
