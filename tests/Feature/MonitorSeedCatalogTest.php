<?php

namespace Tests\Feature;

use App\Models\MonitorCheck;
use App\Models\MonitorSetting;
use App\Models\MonitorTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MonitorSeedCatalogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_development_sites_and_is_idempotent(): void
    {
        $this->artisan('monitor:seed-catalog', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame(7, MonitorTarget::query()->count());
        $this->assertTrue(
            MonitorTarget::query()
                ->where('name', 'BCV OFICIAL INTRA')
                ->where('probe_origin', 'internal')
                ->exists(),
        );
        $this->assertTrue(
            MonitorTarget::query()
                ->where('name', 'BCV OFICIAL EXTRA')
                ->where('probe_origin', 'external')
                ->exists(),
        );
        $this->assertSame('30', MonitorSetting::getValue('chart_history_max_days'));

        $this->artisan('monitor:seed-catalog', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame(7, MonitorTarget::query()->count());
    }

    #[Test]
    public function it_adds_demo_checks_once(): void
    {
        $this->artisan('monitor:seed-catalog', [
            '--force' => true,
            '--demo-checks' => true,
        ])->assertSuccessful();

        $checks = MonitorCheck::query()->count();
        $this->assertGreaterThan(0, $checks);
        $this->assertNotNull(MonitorTarget::query()->whereNotNull('last_checked_at')->first());

        $this->artisan('monitor:seed-catalog', [
            '--force' => true,
            '--demo-checks' => true,
        ])->assertSuccessful();

        $this->assertSame($checks, MonitorCheck::query()->count());
    }
}
