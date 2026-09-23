<?php

namespace Tests\Feature;

use App\Models\MonitorCheck;
use App\Models\MonitorIntervalOption;
use App\Models\MonitorTarget;
use App\Models\User;
use App\Services\Monitoring\SiteLineChart;
use App\Services\Monitoring\ChartRange;
use App\Services\Monitoring\MonitorSettings;
use App\Services\RemoteCommand\RemoteCommandException;
use App\Services\RemoteCommand\RemoteCommandRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RolesAndSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function first_registered_user_is_admin_and_next_is_monitor(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Primer Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->canRunCommands());

        auth()->logout();

        $this->post(route('register.store'), [
            'name' => 'Operador',
            'email' => 'ops@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $monitor = User::query()->where('email', 'ops@example.com')->firstOrFail();
        $this->assertTrue($monitor->isMonitor());
        $this->assertFalse($monitor->canRunCommands());
    }

    #[Test]
    public function monitor_user_cannot_manage_sites_or_settings(): void
    {
        $monitor = User::factory()->monitor()->create();
        $target = MonitorTarget::factory()->create(['name' => 'Portal Interno']);

        $this->actingAs($monitor)
            ->get(route('settings.users'))
            ->assertForbidden();

        $this->actingAs($monitor)
            ->get(route('settings.monitor'))
            ->assertForbidden();

        Livewire::actingAs($monitor)
            ->test('pages::monitor.sites')
            ->assertSee('Portal Interno')
            ->assertDontSeeHtml('wire:click="create"')
            ->call('create')
            ->assertForbidden();

        Livewire::actingAs($monitor)
            ->test('pages::monitor.sites')
            ->call('delete', $target->id)
            ->assertForbidden();

        $this->assertTrue(MonitorTarget::query()->whereKey($target->id)->exists());
    }

    #[Test]
    public function admin_can_set_site_interval_from_managed_list(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test('pages::monitor.sites')
            ->call('create')
            ->set('name', 'Portal 10s')
            ->set('url', 'https://diez.example.com')
            ->set('ssh_host', '')
            ->set('interval_seconds', 10)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(10, MonitorTarget::query()->where('name', 'Portal 10s')->value('interval_seconds'));
    }

    #[Test]
    public function admin_manages_interval_list_and_chart_history_days(): void
    {
        $admin = User::factory()->admin()->create();
        $settings = app(MonitorSettings::class);

        Livewire::actingAs($admin)
            ->test('pages::settings.monitor')
            ->set('chart_history_max_days', 14)
            ->call('saveHistoryDays')
            ->assertHasNoErrors()
            ->set('new_interval_seconds', 7)
            ->call('addInterval')
            ->assertHasNoErrors();

        $this->assertSame(14, $settings->chartHistoryMaxDays());
        $this->assertContains(7, $settings->intervalSeconds());

        $option = MonitorIntervalOption::query()->where('seconds', 7)->firstOrFail();

        Livewire::actingAs($admin)
            ->test('pages::settings.monitor')
            ->call('removeInterval', $option->id)
            ->assertHasNoErrors();

        $this->assertNotContains(7, $settings->intervalSeconds());
    }

    #[Test]
    public function admin_can_grant_command_execution_to_a_monitor_user(): void
    {
        $admin = User::factory()->admin()->create();
        $monitor = User::factory()->monitor()->create();

        Livewire::actingAs($admin)
            ->test('pages::settings.users')
            ->call('edit', $monitor->id)
            ->set('can_run_commands', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($monitor->fresh()->canRunCommands());
    }

    #[Test]
    public function monitor_without_command_permission_cannot_run_commands(): void
    {
        $monitor = User::factory()->monitor()->create();
        $target = MonitorTarget::factory()->create();

        $this->expectException(RemoteCommandException::class);

        app(RemoteCommandRunner::class)->run($monitor, $target, 1);
    }

    #[Test]
    public function chart_range_never_exceeds_admin_max_days(): void
    {
        $options = ChartRange::options(30);
        $this->assertArrayHasKey('15m', $options);
        $this->assertArrayHasKey('1h', $options);
        $this->assertArrayHasKey('7d', $options);
        $this->assertArrayHasKey('max', $options);

        $since = ChartRange::since('7d', 3);
        $this->assertTrue($since->gte(now()->subDays(3)->subSecond()));
        $this->assertSame('15m', ChartRange::normalize('7d', 3));
    }

    #[Test]
    public function dashboard_chart_filters_history_by_selected_range(): void
    {
        $user = User::factory()->admin()->create();
        $target = MonitorTarget::factory()->create([
            'name' => 'Portal Histórico',
            'url' => 'https://historico.example.com',
        ]);

        MonitorCheck::query()->insert([
            [
                'monitor_target_id' => $target->id,
                'ok' => true,
                'status_code' => 200,
                'total_ms' => 50,
                'availability_reason' => 'ok',
                'payload' => json_encode(['ok' => true, 'timings_ms' => ['dns' => 5, 'tcp' => 5, 'total' => 50]]),
                'checked_at' => now()->subDays(10),
            ],
            [
                'monitor_target_id' => $target->id,
                'ok' => true,
                'status_code' => 200,
                'total_ms' => 80,
                'availability_reason' => 'ok',
                'payload' => json_encode(['ok' => true, 'timings_ms' => ['dns' => 5, 'tcp' => 5, 'total' => 80]]),
                'checked_at' => now()->subHour(),
            ],
        ]);

        $series = app(SiteLineChart::class)->series(collect([$target->fresh()]), ChartRange::since('24h', 30));
        $this->assertCount(1, $series);
        $this->assertCount(1, $series[0]['points']);
        $this->assertSame(80, $series[0]['points'][0]['total']);

        Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->assertSee('Últimos 15 minutos')
            ->assertSee('Última hora')
            ->assertSee('Últimos 30 días')
            ->set('chartRange', '1h')
            ->assertSet('chartRange', '1h');
    }

    #[Test]
    public function chart_downsamples_without_floor_on_sqlite(): void
    {
        $target = MonitorTarget::factory()->create([
            'url' => 'https://muchos.example.com',
        ]);

        $rows = [];
        for ($i = 0; $i < 260; $i++) {
            $rows[] = [
                'monitor_target_id' => $target->id,
                'ok' => true,
                'status_code' => 200,
                'total_ms' => 40 + $i,
                'availability_reason' => 'ok',
                'payload' => json_encode(['ok' => true, 'timings_ms' => ['dns' => 5, 'tcp' => 5, 'total' => 40 + $i]]),
                'checked_at' => now()->subMinutes(260 - $i),
            ];
        }
        MonitorCheck::query()->insert($rows);

        $checks = app(SiteLineChart::class)->checksForChart($target->id, now()->subDay());

        $this->assertGreaterThan(0, $checks->count());
        $this->assertLessThanOrEqual(240, $checks->count());
    }
}
