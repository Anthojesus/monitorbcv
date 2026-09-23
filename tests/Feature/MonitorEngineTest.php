<?php

namespace Tests\Feature;

use App\Models\MonitorTarget;
use App\Services\Monitoring\FastApiProbe;
use App\Services\Monitoring\HttpProbe;
use App\Services\Monitoring\MonitorEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MonitorEngineTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_never_checked_target_is_due(): void
    {
        $target = MonitorTarget::factory()->make([
            'interval_seconds' => 15,
            'last_checked_at' => null,
        ]);

        $this->assertTrue($target->isDue());
    }

    #[Test]
    public function a_recently_checked_target_is_not_due(): void
    {
        $target = MonitorTarget::factory()->make([
            'interval_seconds' => 15,
            'last_checked_at' => now(),
        ]);

        $this->assertFalse($target->isDue());
    }

    #[Test]
    public function run_due_only_probes_targets_whose_interval_elapsed(): void
    {
        $due = MonitorTarget::factory()->create([
            'interval_seconds' => 15,
            'last_checked_at' => now()->subSeconds(20),
        ]);

        MonitorTarget::factory()->create([
            'interval_seconds' => 15,
            'last_checked_at' => now(),
        ]);

        $this->mock(FastApiProbe::class, function ($mock): void {
            $mock->shouldReceive('enabled')->andReturn(false);
        });

        $this->mock(HttpProbe::class, function ($mock): void {
            $mock->shouldReceive('probe')->never();
        });

        $ran = app(MonitorEngine::class)->runDue();

        $this->assertSame(1, $ran);
        $this->assertSame(1, $due->fresh()->checks()->count());
    }

    #[Test]
    public function run_due_checks_the_oldest_target_first_when_limited(): void
    {
        $oldest = MonitorTarget::factory()->create([
            'name' => 'Oldest',
            'interval_seconds' => 5,
            'last_checked_at' => now()->subMinutes(10),
        ]);
        $newer = MonitorTarget::factory()->create([
            'name' => 'Newer',
            'interval_seconds' => 5,
            'last_checked_at' => now()->subMinutes(2),
        ]);

        $this->mock(FastApiProbe::class, function ($mock): void {
            $mock->shouldReceive('enabled')->andReturn(false);
        });

        $this->mock(HttpProbe::class, function ($mock): void {
            $mock->shouldReceive('probe')->never();
        });

        $ran = app(MonitorEngine::class)->runDue(1);

        $this->assertSame(1, $ran);
        $this->assertSame(1, $oldest->fresh()->checks()->count());
        $this->assertSame(0, $newer->fresh()->checks()->count());
    }

    #[Test]
    public function external_target_does_not_fall_back_to_php_and_keeps_last_ok(): void
    {
        $checkedAt = now()->subMinute();
        $target = MonitorTarget::factory()->create([
            'probe_origin' => MonitorTarget::ORIGIN_EXTERNAL,
            'last_ok' => true,
            'last_status_code' => 200,
            'last_total_ms' => 80,
            'last_checked_at' => $checkedAt,
        ]);

        $this->mock(FastApiProbe::class, function ($mock): void {
            $mock->shouldReceive('enabled')->andReturn(false);
            $mock->shouldReceive('healthy')->andReturn(false);
        });

        $this->mock(HttpProbe::class, function ($mock): void {
            $mock->shouldReceive('probe')->never();
        });

        $check = app(MonitorEngine::class)->run($target);

        $this->assertFalse($check->ok);
        $this->assertTrue((bool) data_get($check->payload, 'probe_unavailable'));
        $this->assertSame('probe_unavailable', $check->availability_reason);
        $this->assertSame('external', data_get($check->payload, 'origin'));
        $this->assertSame('none', data_get($check->payload, 'engine'));

        $fresh = $target->fresh();
        $this->assertTrue($fresh->last_ok);
        $this->assertSame(200, $fresh->last_status_code);
        $this->assertSame(80, $fresh->last_total_ms);
        $this->assertTrue($fresh->last_checked_at->gt($checkedAt));
    }

    #[Test]
    public function internal_target_does_not_fall_back_to_php_and_keeps_last_ok(): void
    {
        $checkedAt = now()->subMinute();
        $target = MonitorTarget::factory()->create([
            'probe_origin' => MonitorTarget::ORIGIN_INTERNAL,
            'last_ok' => true,
            'last_status_code' => 200,
            'last_total_ms' => 40,
            'last_checked_at' => $checkedAt,
        ]);

        $this->mock(FastApiProbe::class, function ($mock): void {
            $mock->shouldReceive('enabled')->andReturn(false);
            $mock->shouldReceive('healthy')->andReturn(false);
        });

        $this->mock(HttpProbe::class, function ($mock): void {
            $mock->shouldReceive('probe')->never();
        });

        $check = app(MonitorEngine::class)->run($target);

        $this->assertFalse($check->ok);
        $this->assertTrue((bool) data_get($check->payload, 'probe_unavailable'));
        $this->assertSame('probe_unavailable', $check->availability_reason);
        $this->assertSame('internal', data_get($check->payload, 'origin'));
        $this->assertSame('none', data_get($check->payload, 'engine'));
        $this->assertTrue($target->fresh()->last_ok);
        $this->assertSame(200, $target->fresh()->last_status_code);
    }
}
