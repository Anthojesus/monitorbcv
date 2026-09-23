<?php

namespace Tests\Unit;

use App\Models\MonitorCheck;
use App\Models\MonitorTarget;
use App\Services\Monitoring\CheckStats;
use App\Services\Monitoring\OriginCorrelation;
use App\Services\Monitoring\SiteCondition;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteInsightTest extends TestCase
{
    #[Test]
    public function percentile_uses_nearest_rank(): void
    {
        $values = range(1, 100);

        $this->assertSame(95, CheckStats::percentile($values, 95));
        $this->assertSame(99, CheckStats::percentile($values, 99));
        $this->assertNull(CheckStats::percentile([], 95));
    }

    #[Test]
    public function three_slow_ttfb_checks_mark_an_up_site_degraded(): void
    {
        $target = (new MonitorTarget)->forceFill([
            'id' => 1,
            'last_ok' => true,
            'last_status_code' => 200,
        ]);
        $checks = Collection::make([
            $this->check(true, 1400),
            $this->check(true, 1300),
            $this->check(true, 1200),
        ]);

        $condition = app(SiteCondition::class)->evaluate($target, $checks);

        $this->assertSame('degraded', $condition['key']);
        $this->assertTrue($condition['slow']);
    }

    #[Test]
    public function reachable_500_is_degraded_not_ok(): void
    {
        $target = (new MonitorTarget)->forceFill([
            'id' => 2,
            'last_ok' => true,
            'last_status_code' => 500,
        ]);

        $condition = app(SiteCondition::class)->evaluate($target, Collection::make([
            $this->check(true, 80, 500),
        ]));

        $this->assertSame('degraded', $condition['key']);
        $this->assertSame('critical', $condition['severity']);
    }

    #[Test]
    public function stale_unavailable_is_not_unmonitored_when_probe_is_up(): void
    {
        $target = (new MonitorTarget)->forceFill([
            'id' => 10,
            'probe_origin' => MonitorTarget::ORIGIN_INTERNAL,
            'last_ok' => true,
            'last_status_code' => 200,
        ]);
        $checks = Collection::make([
            (new MonitorCheck)->forceFill([
                'ok' => false,
                'availability_reason' => 'probe_unavailable',
                'payload' => ['probe_unavailable' => true],
            ]),
        ]);

        $condition = app(SiteCondition::class)->evaluate($target, $checks, true);

        $this->assertSame('ok', $condition['key']);
        $this->assertStringContainsString('ya está arriba', $condition['detail']);
    }

    #[Test]
    public function unavailable_stays_unmonitored_when_probe_is_down(): void
    {
        $target = (new MonitorTarget)->forceFill([
            'id' => 10,
            'probe_origin' => MonitorTarget::ORIGIN_INTERNAL,
            'last_ok' => true,
        ]);
        $checks = Collection::make([
            (new MonitorCheck)->forceFill([
                'ok' => false,
                'availability_reason' => 'probe_unavailable',
                'payload' => ['probe_unavailable' => true],
            ]),
        ]);

        $condition = app(SiteCondition::class)->evaluate($target, $checks, false);

        $this->assertSame('unmonitored', $condition['key']);
    }

    #[Test]
    public function interior_up_and_exterior_down_is_outside_only(): void
    {
        $internal = (new MonitorTarget)->forceFill([
            'id' => 10,
            'name' => 'Portal Interior',
            'url' => 'https://Biblioteca.extra.bcv.org.ve/',
            'probe_origin' => MonitorTarget::ORIGIN_INTERNAL,
        ]);
        $external = (new MonitorTarget)->forceFill([
            'id' => 11,
            'name' => 'Portal Exterior',
            'url' => 'https://biblioteca.extra.bcv.org.ve',
            'probe_origin' => MonitorTarget::ORIGIN_EXTERNAL,
        ]);

        $diagnoses = app(OriginCorrelation::class)->diagnoses(Collection::make([$internal, $external]), [
            10 => ['key' => 'ok', 'slow' => false],
            11 => ['key' => 'down', 'slow' => false],
        ]);

        $this->assertCount(1, $diagnoses);
        $this->assertSame('outside_only', $diagnoses[0]['key']);
        $this->assertSame(
            OriginCorrelation::normalizeUrl('https://biblioteca.extra.bcv.org.ve/'),
            $diagnoses[0]['url'],
        );
    }

    private function check(bool $ok, int $ttfb, int $status = 200): MonitorCheck
    {
        return (new MonitorCheck)->forceFill([
            'ok' => $ok,
            'status_code' => $status,
            'availability_reason' => $ok ? 'ok' : 'unexpected_status',
            'payload' => [
                'timings_ms' => ['ttfb' => $ttfb, 'total' => $ttfb + 20],
            ],
        ]);
    }
}
