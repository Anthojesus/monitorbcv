<?php

namespace App\Services\Monitoring;

use App\Models\MonitorCheck;
use App\Models\MonitorTarget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MonitorEngine
{
    public function __construct(
        private HttpProbe $httpProbe,
        private FastApiProbe $fastApiProbe,
        private HealthPayloadAnalyzer $healthAnalyzer,
    ) {}

    public function run(MonitorTarget $target): MonitorCheck
    {
        $payload = $this->healthAnalyzer->enrich($target, $this->collect($target));
        $unavailable = (bool) ($payload['probe_unavailable'] ?? false);

        $check = new MonitorCheck;
        $check->monitor_target_id = $target->id;
        $check->ok = $unavailable ? false : (bool) ($payload['ok'] ?? false);
        $check->status_code = data_get($payload, 'http.status');
        $check->total_ms = data_get($payload, 'timings_ms.total');
        $check->availability_reason = data_get($payload, 'availability.reason');
        $check->payload = $payload;
        $check->checked_at = now();
        $check->save();

        if (! $unavailable) {
            $target->forceFill([
                'last_ok' => $check->ok,
                'last_status_code' => $check->status_code,
                'last_total_ms' => $check->total_ms,
                'last_checked_at' => $check->checked_at,
            ])->save();
        } else {
            $target->forceFill([
                'last_checked_at' => $check->checked_at,
            ])->save();
        }

        $this->appendJsonl($target, $payload);

        return $check;
    }

    public function runDue(?int $limit = null): int
    {
        $lock = Cache::lock('monitor:run-due', 45);

        if (! $lock->get()) {
            return 0;
        }

        try {
            $ran = 0;
            $limit ??= $this->dueLimit();
            $budgetMs = $this->dueBudgetMs();
            $started = hrtime(true);

            foreach ($this->dueTargets() as $target) {
                if (! $target->isDue()) {
                    continue;
                }

                if ($ran >= $limit) {
                    break;
                }

                if ($budgetMs !== null && ((hrtime(true) - $started) / 1_000_000) >= $budgetMs) {
                    break;
                }

                $this->run($target);
                $ran++;
            }

            return $ran;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, MonitorTarget>
     */
    private function dueTargets()
    {
        return MonitorTarget::query()
            ->where('is_enabled', true)
            ->orderByRaw('last_checked_at is null desc')
            ->orderBy('last_checked_at')
            ->orderBy('id')
            ->get();
    }

    private function dueLimit(): int
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return PHP_INT_MAX;
        }

        return max(1, (int) config('monitor.web_tick_limit', 3));
    }

    private function dueBudgetMs(): ?int
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return null;
        }

        return max(1000, (int) config('monitor.web_tick_budget_ms', 12000));
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(MonitorTarget $target): array
    {
        $origin = $target->probeOrigin();

        if ($this->fastApiProbe->enabled($origin) && $this->fastApiProbe->healthy($origin)) {
            try {
                return $this->stamp($this->fastApiProbe->probe($target), $origin, 'fastapi');
            } catch (Throwable $exception) {
                return $this->unavailable($origin, $exception->getMessage());
            }
        }

        $side = $origin === MonitorTarget::ORIGIN_EXTERNAL ? 'exterior' : 'interior';

        return $this->unavailable($origin, 'El servicio '.$side.' no está habilitado o no responde.');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stamp(array $payload, string $origin, string $engine): array
    {
        $payload['origin'] = $origin;
        $payload['engine'] = $engine;
        $payload['probe_unavailable'] = false;
        $payload['probe_host'] = $this->probeHost($origin, $engine);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(string $origin, string $message): array
    {
        return [
            'ok' => false,
            'probe_unavailable' => true,
            'origin' => $origin,
            'engine' => 'none',
            'probe_host' => $this->probeHost($origin, 'none'),
            'availability' => [
                'up' => false,
                'reason' => 'probe_unavailable',
            ],
            'error' => [
                'message' => $message,
            ],
            'http' => [
                'status' => null,
            ],
            'timings_ms' => [
                'total' => null,
            ],
        ];
    }

    private function probeHost(string $origin, string $engine): ?string
    {
        if ($engine === 'php-curl') {
            return gethostname() ?: null;
        }

        if ($engine !== 'fastapi') {
            return null;
        }

        $host = parse_url($this->fastApiProbe->configFor($origin)['url'], PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function appendJsonl(MonitorTarget $target, array $payload): void
    {
        $date = now()->toDateString();
        $path = sprintf('%s/%d/%s.jsonl', (string) config('monitor.jsonl_path'), $target->id, $date);

        Storage::disk('local')->append(
            $path,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        );
    }
}
