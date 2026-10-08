<?php

namespace Tests\Unit;

use App\Models\MonitorTarget;
use App\Services\Monitoring\AvailabilityVerdict;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AvailabilityVerdictTest extends TestCase
{
    #[Test]
    public function http_reachable_502_is_page_down_and_host_ok(): void
    {
        $target = (new MonitorTarget)->forceFill([
            'kind' => MonitorTarget::KIND_HTTP,
            'expected_status' => [MonitorTarget::ANY_HTTP_STATUS],
        ]);

        $verdict = app(AvailabilityVerdict::class)->evaluate($target, 502, ['check' => []], null);

        $this->assertFalse($verdict['up']);
        $this->assertSame('http_5xx', $verdict['reason']);
        $this->assertTrue($verdict['host_ok']);
        $this->assertFalse($verdict['page_ok']);
    }

    #[Test]
    public function proxy_reachable_502_stays_host_up(): void
    {
        $target = (new MonitorTarget)->forceFill([
            'kind' => MonitorTarget::KIND_PROXY,
            'expected_status' => [MonitorTarget::ANY_HTTP_STATUS],
        ]);

        $verdict = app(AvailabilityVerdict::class)->evaluate($target, 502, ['check' => []], null);

        $this->assertTrue($verdict['up']);
        $this->assertSame('http_reachable', $verdict['reason']);
        $this->assertTrue($verdict['host_ok']);
        $this->assertFalse($verdict['page_ok']);
    }

    #[Test]
    public function apply_overrides_fastapi_ok_on_portal_502(): void
    {
        $target = (new MonitorTarget)->forceFill([
            'kind' => MonitorTarget::KIND_HTTP,
            'expected_status' => [MonitorTarget::ANY_HTTP_STATUS],
        ]);

        $payload = app(AvailabilityVerdict::class)->apply($target, [
            'ok' => true,
            'availability' => ['up' => true, 'reason' => 'http_reachable'],
            'http' => ['status' => 502],
            'content_check' => [],
        ]);

        $this->assertFalse($payload['ok']);
        $this->assertSame('http_5xx', $payload['availability']['reason']);
        $this->assertTrue($payload['availability']['host_ok']);
        $this->assertFalse($payload['availability']['page_ok']);
    }

    #[Test]
    public function apply_keeps_health_down_reason(): void
    {
        $target = (new MonitorTarget)->forceFill([
            'kind' => MonitorTarget::KIND_HEALTH,
            'expected_status' => [200],
        ]);

        $payload = app(AvailabilityVerdict::class)->apply($target, [
            'ok' => false,
            'availability' => ['up' => false, 'reason' => 'health_down'],
            'http' => ['status' => 200],
            'content_check' => [],
        ]);

        $this->assertFalse($payload['ok']);
        $this->assertSame('health_down', $payload['availability']['reason']);
        $this->assertTrue($payload['availability']['host_ok']);
        $this->assertFalse($payload['availability']['page_ok']);
    }
}
