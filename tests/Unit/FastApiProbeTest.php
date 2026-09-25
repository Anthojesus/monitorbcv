<?php

namespace Tests\Unit;

use App\Models\MonitorTarget;
use App\Services\Monitoring\FastApiProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FastApiProbeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function runtime_reports_api_and_python_down_when_disabled(): void
    {
        config([
            'monitor.probes.internal.enabled' => false,
            'monitor.probes.internal.url' => '',
            'monitor.probes.external.enabled' => false,
            'monitor.probes.external.url' => '',
        ]);

        $runtime = app(FastApiProbe::class)->runtime();

        $this->assertFalse($runtime['api']['ok']);
        $this->assertFalse($runtime['python']['ok']);
        $this->assertFalse($runtime['internal']['api']['ok']);
        $this->assertFalse($runtime['external']['api']['ok']);
        $this->assertSame('php-curl', $runtime['engine']);
    }

    #[Test]
    public function runtime_reports_api_and_python_ok_when_health_answers(): void
    {
        config([
            'monitor.probes.internal.enabled' => true,
            'monitor.probes.internal.url' => 'http://127.0.0.1:8100',
            'monitor.probes.external.enabled' => false,
            'monitor.probes.external.url' => '',
        ]);

        Http::fake([
            'http://127.0.0.1:8100/health' => Http::response([
                'status' => 'ok',
                'probe' => 'ok',
            ]),
        ]);

        app(FastApiProbe::class)->refreshRuntime();
        $runtime = app(FastApiProbe::class)->runtime();

        $this->assertTrue($runtime['api']['ok']);
        $this->assertTrue($runtime['python']['ok']);
        $this->assertTrue($runtime['internal']['api']['ok']);
        $this->assertFalse($runtime['external']['api']['ok']);
        $this->assertSame('fastapi', $runtime['engine']);
    }

    #[Test]
    public function runtime_does_not_call_health_from_the_web(): void
    {
        config([
            'monitor.probes.internal.enabled' => true,
            'monitor.probes.internal.url' => 'http://127.0.0.1:8100',
            'monitor.probes.external.enabled' => true,
            'monitor.probes.external.url' => 'https://monitor.example.test',
        ]);

        Http::fake();

        $runtime = app(FastApiProbe::class)->runtime();

        $this->assertFalse($runtime['internal']['api']['ok']);
        $this->assertFalse($runtime['external']['api']['ok']);
        Http::assertNothingSent();
    }

    #[Test]
    public function runtime_health_is_cached_across_instances(): void
    {
        config([
            'monitor.probes.internal.enabled' => true,
            'monitor.probes.internal.url' => 'http://127.0.0.1:8100',
            'monitor.probes.external.enabled' => false,
            'monitor.runtime_cache_seconds' => 20,
        ]);

        Http::fake([
            'http://127.0.0.1:8100/health' => Http::response([
                'status' => 'ok',
                'probe' => 'ok',
            ]),
        ]);

        app(FastApiProbe::class)->refreshRuntime('internal');
        app()->forgetInstance(FastApiProbe::class);
        app(FastApiProbe::class)->runtime('internal');

        Http::assertSentCount(1);
    }

    #[Test]
    public function probe_sends_bearer_token(): void
    {
        config([
            'monitor.probes.internal.enabled' => true,
            'monitor.probes.internal.url' => 'http://127.0.0.1:8100',
            'monitor.probes.internal.token' => 'local-dev-token',
        ]);

        Http::fake([
            'http://127.0.0.1:8100/v1/checks' => Http::response([
                'ok' => true,
                'http' => ['status' => 200],
                'timings_ms' => ['total' => 10],
                'availability' => ['reason' => 'ok'],
            ]),
        ]);

        app(FastApiProbe::class)->probe(MonitorTarget::factory()->make(['id' => 1]));

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Authorization', 'Bearer local-dev-token')
                && str_ends_with($request->url(), '/v1/checks');
        });
    }

    #[Test]
    public function logs_reads_authenticated_events_from_the_probe(): void
    {
        config([
            'monitor.probes.external.enabled' => true,
            'monitor.probes.external.url' => 'https://monitor.example.test',
            'monitor.probes.external.token' => 'external-token',
        ]);

        Http::fake([
            'https://monitor.example.test/health' => Http::response(['status' => 'ok', 'probe' => 'ok']),
            'https://monitor.example.test/v1/logs' => Http::response([
                'count' => 1,
                'events' => [
                    ['kind' => 'check', 'ok' => true, 'target' => 'Example', 'at' => now()->toIso8601String()],
                ],
            ]),
        ]);

        $logs = app(FastApiProbe::class)->logs('external');

        $this->assertTrue($logs['ok']);
        $this->assertSame('Example', $logs['events'][0]['target']);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer external-token')
            && str_ends_with($request->url(), '/v1/logs'));
    }

    #[Test]
    public function health_check_does_not_send_bearer_and_treats_status_ok_as_probe_up(): void
    {
        config([
            'monitor.probes.external.enabled' => true,
            'monitor.probes.external.url' => 'https://monitor.example.test',
            'monitor.probes.external.token' => 'external-token',
            'monitor.fastapi.health_timeout' => 8,
            'monitor.fastapi.connect_timeout' => 3,
        ]);

        Http::fake([
            'https://monitor.example.test/health' => Http::response([
                'status' => 'ok',
                'service' => 'monitor-bcv',
            ]),
        ]);

        $runtime = app(FastApiProbe::class)->refreshRuntime('external');

        $this->assertTrue($runtime['api']['ok']);
        $this->assertTrue($runtime['python']['ok']);
        Http::assertSent(function ($request): bool {
            return str_ends_with($request->url(), '/health')
                && ! $request->hasHeader('Authorization');
        });
    }
}
