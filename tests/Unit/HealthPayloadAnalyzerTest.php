<?php

namespace Tests\Unit;

use App\Models\MonitorTarget;
use App\Services\Monitoring\HealthPayloadAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthPayloadAnalyzerTest extends TestCase
{
    #[Test]
    public function it_parses_microprofile_health_json(): void
    {
        $health = app(HealthPayloadAnalyzer::class)->parse([
            'status' => 'UP',
            'checks' => [
                ['name' => 'ServiceHealthCheck', 'status' => 'UP'],
                ['name' => 'LDAP check', 'status' => 'UP', 'data' => ['status' => 'ALIVE', 'from' => '2026-07-03 17:00:15']],
                ['name' => 'Base de datos check', 'status' => 'UP', 'data' => ['status' => 'ALIVE', 'from' => '2026-07-03 17:00:26']],
            ],
        ]);

        $this->assertNotNull($health);
        $this->assertTrue($health['up']);
        $this->assertSame('microprofile', $health['format']);
        $this->assertSame(3, $health['up_count']);
        $this->assertSame('LDAP check', $health['checks'][1]['name']);
        $this->assertStringContainsString('ALIVE', (string) $health['checks'][1]['detail']);
    }

    #[Test]
    public function it_marks_the_service_down_when_a_dependency_fails(): void
    {
        $target = new MonitorTarget(['kind' => 'health']);
        $payload = app(HealthPayloadAnalyzer::class)->enrich($target, [
            'ok' => true,
            'availability' => ['up' => true, 'reason' => 'ok'],
            'http' => [
                'status' => 200,
                'json' => [
                    'status' => 'DOWN',
                    'checks' => [
                        ['name' => 'LDAP check', 'status' => 'DOWN', 'data' => ['status' => 'DEAD']],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($payload['ok']);
        $this->assertSame('health_check_down', $payload['availability']['reason']);
        $this->assertSame('DOWN', $payload['health']['checks'][0]['status']);
    }

    #[Test]
    public function it_parses_status_inside_a_data_envelope(): void
    {
        $health = app(HealthPayloadAnalyzer::class)->parse([
            'success' => true,
            'data' => [
                'status' => 'ok',
                'service' => 'integracorp-api',
                'uptime_seconds' => 787623,
                'pid' => 108548,
                'port' => 4000,
                'timestamp' => '2026-09-10T00:32:16.086Z',
            ],
        ]);

        $this->assertNotNull($health);
        $this->assertTrue($health['up']);
        $this->assertSame('generic', $health['format']);
        $this->assertSame('OK', $health['status']);
        $this->assertSame(0, $health['down_count']);
    }

    #[Test]
    public function it_marks_wrapped_health_down_when_inner_status_fails(): void
    {
        $target = new MonitorTarget(['kind' => 'health']);
        $payload = app(HealthPayloadAnalyzer::class)->enrich($target, [
            'ok' => true,
            'availability' => ['up' => true, 'reason' => 'ok'],
            'http' => [
                'status' => 200,
                'json' => [
                    'success' => true,
                    'data' => ['status' => 'down', 'service' => 'integracorp-api'],
                ],
            ],
        ]);

        $this->assertFalse($payload['ok']);
        $this->assertSame('health_down', $payload['availability']['reason']);
        $this->assertSame('DOWN', $payload['health']['status']);
    }

    #[Test]
    public function it_treats_a_success_flag_as_generic_health(): void
    {
        $analyzer = app(HealthPayloadAnalyzer::class);

        $up = $analyzer->parse(['success' => true]);
        $down = $analyzer->parse(['success' => false]);
        $result = $analyzer->parse(['result' => ['healthy' => true]]);
        $payload = $analyzer->parse(['payload' => ['state' => 'alive']]);

        $this->assertTrue($up['up']);
        $this->assertFalse($down['up']);
        $this->assertTrue($result['up']);
        $this->assertTrue($payload['up']);
        $this->assertSame('ALIVE', $payload['status']);
    }

    #[Test]
    public function it_parses_microprofile_inside_a_result_envelope(): void
    {
        $health = app(HealthPayloadAnalyzer::class)->parse([
            'result' => [
                'status' => 'UP',
                'checks' => [
                    ['name' => 'database', 'status' => 'UP'],
                ],
            ],
        ]);

        $this->assertNotNull($health);
        $this->assertTrue($health['up']);
        $this->assertSame('microprofile', $health['format']);
        $this->assertSame(1, $health['up_count']);
    }

    #[Test]
    public function it_still_rejects_unrecognized_json(): void
    {
        $target = new MonitorTarget(['kind' => 'health']);
        $payload = app(HealthPayloadAnalyzer::class)->enrich($target, [
            'ok' => true,
            'availability' => ['up' => true, 'reason' => 'ok'],
            'http' => [
                'status' => 200,
                'json' => ['message' => 'pong', 'version' => '1.0'],
            ],
        ]);

        $this->assertFalse($payload['ok']);
        $this->assertSame('health_invalid', $payload['availability']['reason']);
        $this->assertArrayNotHasKey('health', $payload);
    }
}
