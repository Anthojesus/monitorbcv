<?php

namespace Tests\Feature;

use App\Models\MonitorCheck;
use App\Models\MonitorTarget;
use App\Models\User;
use App\Services\Monitoring\DashboardMetrics;
use App\Services\Monitoring\FastApiProbe;
use App\Services\Monitoring\HttpProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MonitorDashboardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_cannot_view_the_command_center(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('monitor.sites'))->assertRedirect(route('login'));
        $this->get(route('documentation'))->assertRedirect(route('login'));
        $this->get(route('monitor.apis'))->assertRedirect(route('login'));
    }

    #[Test]
    public function authenticated_users_can_view_monitor_pages(): void
    {
        $user = User::factory()->create();
        $target = MonitorTarget::factory()->create(['name' => 'Portal BCV']);

        MonitorCheck::query()->insert([
            'monitor_target_id' => $target->id,
            'ok' => true,
            'status_code' => 200,
            'total_ms' => 180,
            'availability_reason' => 'ok',
            'payload' => json_encode(['ok' => true, 'http' => ['status' => 200]]),
            'checked_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Centro de Control')
            ->assertSee('Portal BCV')
            ->assertSee('Interior')
            ->assertSee('Sitios monitoreados')
            ->assertSee('Fiabilidad')
            ->assertSee('Servidor:')
            ->assertSee('Latencia de red')
            ->assertSee('Comparativo HTTPS en el tiempo')
            ->assertSee('Sitios interiores')
            ->assertSee('Sitios exteriores')
            ->assertSee('Portal BCV')
            ->assertSee('1 de 1 OK')
            ->assertDontSee('Tasa de éxito por URL');

        $this->actingAs($user)
            ->get(route('monitor.sites'))
            ->assertOk()
            ->assertSee('Sitios web')
            ->assertSee('Interior');

        $this->actingAs($user)
            ->get(route('monitor.sites.show', $target))
            ->assertOk()
            ->assertSee('Portal BCV')
            ->assertSee('Interior')
            ->assertSee('Evolución de este sitio')
            ->assertSee('Acciones de servidor');

        $this->actingAs($user)
            ->get(route('documentation'))
            ->assertOk()
            ->assertSee('Documentación de métricas')
            ->assertSee('Headers de seguridad')
            ->assertSee('Strict-Transport-Security')
            ->assertSee('Acciones de servidor')
            ->assertSee('UP 500')
            ->assertSee('API interior y API exterior')
            ->assertSee('uvicorn main:app')
            ->assertSee('GET /health')
            ->assertSee('POST /v1/checks')
            ->assertSee('Regla de crisis')
            ->assertSee('Proxy Linux')
            ->assertSee('Falla del proxy')
            ->assertSee('Falla de la aplicación')
            ->assertSee('Despliegue de la sonda exterior')
            ->assertSee('IP_PUBLICA_LARAVEL')
            ->assertSee('MONITOR_API_EXTERNAL_URL');

        $this->actingAs($user)
            ->get(route('monitor.apis'))
            ->assertOk()
            ->assertSee('Logs de las sondas')
            ->assertSee('API Interior')
            ->assertSee('API Exterior');
    }

    #[Test]
    public function dashboard_shows_python_api_runtime_badges(): void
    {
        config([
            'monitor.probes.internal.enabled' => true,
            'monitor.probes.internal.url' => 'http://127.0.0.1:8100',
            'monitor.probes.external.enabled' => true,
            'monitor.probes.external.url' => 'http://127.0.0.1:8200',
        ]);

        Http::fake([
            'http://127.0.0.1:8100/health' => Http::response([
                'status' => 'ok',
                'probe' => 'ok',
                'version' => '1.1.0',
            ]),
            'http://127.0.0.1:8200/health' => Http::response([
                'status' => 'ok',
                'probe' => 'ok',
                'version' => '1.1.0',
            ]),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('API Interior · OK')
            ->assertSee('Sonda Interior · OK')
            ->assertSee('API Exterior · OK')
            ->assertSee('Sonda Exterior · OK');
    }

    #[Test]
    public function dashboard_shows_origin_badges_and_stale_callout_for_external_probe(): void
    {
        MonitorTarget::factory()->create([
            'name' => 'Portal Interno',
            'probe_origin' => MonitorTarget::ORIGIN_INTERNAL,
        ]);
        MonitorTarget::factory()->create([
            'name' => 'Portal Público',
            'probe_origin' => MonitorTarget::ORIGIN_EXTERNAL,
        ]);

        $this->mock(FastApiProbe::class, function ($mock): void {
            $mock->shouldReceive('enabled')->andReturn(false);
            $mock->shouldReceive('engineLabel')->andReturn('php-curl');
            $mock->shouldReceive('runtime')->andReturn($this->disabledRuntime());
        });

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Portal Interno')
            ->assertSee('Portal Público')
            ->assertSee('Interior')
            ->assertSee('Exterior')
            ->assertSee('No se está monitoreando')
            ->assertSee('API Interior')
            ->assertSee('API Exterior');
    }

    #[Test]
    public function dashboard_tick_runs_due_probes(): void
    {
        $user = User::factory()->create();
        $target = MonitorTarget::factory()->create([
            'name' => 'Radar BCV',
            'last_checked_at' => null,
        ]);

        $this->mock(FastApiProbe::class, function ($mock): void {
            $mock->shouldReceive('enabled')->andReturn(false);
            $mock->shouldReceive('engineLabel')->andReturn('php-curl');
            $mock->shouldReceive('runtime')->andReturn($this->disabledRuntime());
        });

        $this->mock(HttpProbe::class, function ($mock): void {
            $mock->shouldReceive('probe')->andReturn([
                'ok' => true,
                'http' => ['status' => 200],
                'timings_ms' => ['total' => 42],
                'availability' => ['reason' => 'ok'],
            ]);
        });

        Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->call('tick')
            ->assertOk()
            ->assertSee('Radar BCV');

        $this->assertNotNull($target->fresh()->last_checked_at);
    }

    #[Test]
    public function dashboard_chart_keeps_metric_and_hidden_sites_across_ticks(): void
    {
        $user = User::factory()->create();
        $alpha = MonitorTarget::factory()->create(['name' => 'Sitio Alpha']);
        $beta = MonitorTarget::factory()->create(['name' => 'Sitio Beta']);

        foreach ([$alpha, $beta] as $target) {
            MonitorCheck::query()->insert([
                'monitor_target_id' => $target->id,
                'ok' => true,
                'status_code' => 200,
                'total_ms' => 90,
                'availability_reason' => 'ok',
                'payload' => json_encode(['ok' => true, 'timings_ms' => ['dns' => 10, 'tcp' => 12, 'total' => 90]]),
                'checked_at' => now(),
            ]);
        }

        $this->mock(FastApiProbe::class, function ($mock): void {
            $mock->shouldReceive('enabled')->andReturn(false);
            $mock->shouldReceive('engineLabel')->andReturn('php-curl');
            $mock->shouldReceive('runtime')->andReturn($this->disabledRuntime());
        });

        Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->assertSee('Comparativo HTTPS en el tiempo')
            ->assertSee('Sitios interiores')
            ->assertSee('Sitios exteriores')
            ->assertSee('Sitio Alpha')
            ->assertSee('Sitio Beta')
            ->set('chartMetric', 'net')
            ->call('toggleChartSite', $alpha->id)
            ->call('tick')
            ->assertSet('chartMetric', 'net')
            ->assertSet('hiddenChartIds', [$alpha->id])
            ->call('showAllChartSites')
            ->assertSet('hiddenChartIds', [])
            ->call('soloChartSite', $beta->id)
            ->assertSet('hiddenChartIds', [$alpha->id]);
    }

    #[Test]
    public function dashboard_splits_chart_series_by_probe_origin(): void
    {
        $user = User::factory()->create();
        $internal = MonitorTarget::factory()->create([
            'name' => 'Portal Interior',
            'url' => 'https://interno.example.com',
            'probe_origin' => MonitorTarget::ORIGIN_INTERNAL,
        ]);
        $external = MonitorTarget::factory()->create([
            'name' => 'Portal Exterior',
            'url' => 'https://externo.example.com',
            'probe_origin' => MonitorTarget::ORIGIN_EXTERNAL,
        ]);

        foreach ([$internal, $external] as $target) {
            MonitorCheck::query()->insert([
                'monitor_target_id' => $target->id,
                'ok' => true,
                'status_code' => 200,
                'total_ms' => 90,
                'availability_reason' => 'ok',
                'payload' => json_encode(['ok' => true, 'timings_ms' => ['dns' => 10, 'tcp' => 12, 'total' => 90]]),
                'checked_at' => now(),
            ]);
        }

        $this->mock(FastApiProbe::class, function ($mock): void {
            $mock->shouldReceive('enabled')->andReturn(false);
            $mock->shouldReceive('engineLabel')->andReturn('php-curl');
            $mock->shouldReceive('runtime')->andReturn($this->disabledRuntime());
        });

        $metrics = app(DashboardMetrics::class)->all()['http'];

        $this->assertSame(['Portal Interior'], array_column($metrics['site_series_internal'], 'name'));
        $this->assertSame(['Portal Exterior'], array_column($metrics['site_series_external'], 'name'));

        Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->assertSee('Sitios interiores')
            ->assertSee('Sitios exteriores')
            ->assertSee('Portal Interior')
            ->assertSee('Portal Exterior');
    }

    #[Test]
    public function dashboard_correlates_origins_and_shows_percentiles(): void
    {
        $user = User::factory()->create();
        $url = 'https://biblioteca.extra.bcv.org.ve/';
        $internal = MonitorTarget::factory()->create([
            'name' => 'Biblio Interior',
            'url' => $url,
            'probe_origin' => MonitorTarget::ORIGIN_INTERNAL,
            'last_ok' => true,
            'last_status_code' => 200,
            'last_total_ms' => 80,
        ]);
        $external = MonitorTarget::factory()->create([
            'name' => 'Biblio Exterior',
            'url' => 'https://biblioteca.extra.bcv.org.ve',
            'probe_origin' => MonitorTarget::ORIGIN_EXTERNAL,
            'last_ok' => false,
            'last_status_code' => null,
            'last_total_ms' => null,
        ]);

        MonitorCheck::query()->insert([
            [
                'monitor_target_id' => $internal->id,
                'ok' => true,
                'status_code' => 200,
                'total_ms' => 80,
                'availability_reason' => 'ok',
                'payload' => json_encode(['ok' => true, 'timings_ms' => ['ttfb' => 40, 'total' => 80]]),
                'checked_at' => now(),
            ],
            [
                'monitor_target_id' => $external->id,
                'ok' => false,
                'status_code' => null,
                'total_ms' => null,
                'availability_reason' => 'timeout',
                'payload' => json_encode(['ok' => false, 'availability' => ['reason' => 'timeout']]),
                'checked_at' => now(),
            ],
        ]);

        $this->mock(FastApiProbe::class, function ($mock): void {
            $mock->shouldReceive('enabled')->andReturn(false);
            $mock->shouldReceive('engineLabel')->andReturn('php-curl');
            $mock->shouldReceive('runtime')->andReturn($this->disabledRuntime());
        });

        Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->assertSee('Qué está fallando')
            ->assertSee('Caído solo afuera')
            ->assertSee('Degradados')
            ->assertSee('p95');
    }

    #[Test]
    public function dashboard_and_detail_show_health_json_checks(): void
    {
        $user = User::factory()->create();
        $target = MonitorTarget::factory()->create([
            'name' => 'OCPP Health Test',
            'kind' => 'health',
            'url' => 'https://ocppws.extra.bcv.org.ve/OCPP/health',
        ]);

        MonitorCheck::query()->insert([
            'monitor_target_id' => $target->id,
            'ok' => true,
            'status_code' => 200,
            'total_ms' => 90,
            'availability_reason' => 'ok',
            'payload' => json_encode([
                'ok' => true,
                'health' => [
                    'format' => 'microprofile',
                    'status' => 'UP',
                    'up' => true,
                    'up_count' => 2,
                    'down_count' => 0,
                    'checks' => [
                        ['name' => 'LDAP check', 'status' => 'UP', 'up' => true, 'detail' => 'status: ALIVE', 'data' => ['status' => 'ALIVE']],
                        ['name' => 'Base de datos check', 'status' => 'UP', 'up' => true, 'detail' => 'status: ALIVE', 'data' => ['status' => 'ALIVE']],
                    ],
                ],
            ]),
            'checked_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Web services en vivo')
            ->assertSee('LDAP check')
            ->assertSee('Base de datos check');

        $this->actingAs($user)
            ->get(route('monitor.sites.show', $target))
            ->assertOk()
            ->assertSee('Dependencias del servicio')
            ->assertSee('LDAP check');
    }

    #[Test]
    public function site_history_paginates_ten_latest_checks(): void
    {
        $user = User::factory()->create();
        $target = MonitorTarget::factory()->create(['name' => 'Portal Historial']);

        foreach (range(0, 11) as $offset) {
            MonitorCheck::query()->insert([
                'monitor_target_id' => $target->id,
                'ok' => true,
                'status_code' => 200,
                'total_ms' => 900 + $offset,
                'availability_reason' => 'ok',
                'payload' => json_encode(['ok' => true]),
                'checked_at' => now()->subMinutes($offset),
            ]);
        }

        $this->actingAs($user)
            ->get(route('monitor.sites.show', $target))
            ->assertOk()
            ->assertSee('10 por página')
            ->assertSee('Showing 1 to 10 of 12 results');

        Livewire::actingAs($user)
            ->test('pages::monitor.site', ['target' => $target])
            ->assertSee('Showing 1 to 10 of 12 results')
            ->call('gotoPage', 2)
            ->assertSee('Showing 11 to 12 of 12 results');
    }

    #[Test]
    public function it_explains_up_500_as_reachable_not_healthy(): void
    {
        $user = User::factory()->create();
        $target = MonitorTarget::factory()->create([
            'name' => 'Biblioteca Extra',
            'last_ok' => true,
            'last_status_code' => 500,
        ]);

        MonitorCheck::query()->insert([
            'monitor_target_id' => $target->id,
            'ok' => true,
            'status_code' => 500,
            'total_ms' => 163,
            'availability_reason' => 'http_reachable',
            'payload' => json_encode([
                'ok' => true,
                'availability' => ['up' => true, 'reason' => 'http_reachable'],
                'http' => ['status' => 500],
            ]),
            'checked_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Degradado')
            ->assertSee('HTTP 500')
            ->assertSee('no es un corte de red')
            ->assertSee('Solo códigos HTTP esperados');

        $this->actingAs($user)
            ->get(route('monitor.sites.show', $target))
            ->assertOk()
            ->assertSee('UP 500')
            ->assertSee('fallo de la aplicación');
    }

    #[Test]
    public function admin_and_monitor_can_reorder_and_hide_dashboard_rows(): void
    {
        $alpha = MonitorTarget::factory()->create(['name' => 'Sitio Alpha']);
        $beta = MonitorTarget::factory()->create(['name' => 'Sitio Beta']);

        $this->mock(FastApiProbe::class, function ($mock): void {
            $mock->shouldReceive('enabled')->andReturn(false);
            $mock->shouldReceive('engineLabel')->andReturn('php-curl');
            $mock->shouldReceive('runtime')->andReturn($this->disabledRuntime());
        });

        foreach ([User::factory()->admin()->create(), User::factory()->monitor()->create()] as $user) {
            Livewire::actingAs($user)
                ->test('pages::dashboard')
                ->assertSee('Subir fila')
                ->assertSee('Ocultar de mi vista')
                ->call('moveTableSiteDown', $alpha->id)
                ->call('hideTableSite', $beta->id)
                ->assertSee('Ocultos en tu vista');

            $prefs = $user->fresh()->dashboard_table;
            $this->assertSame([$beta->id, $alpha->id], $prefs['order']);
            $this->assertSame([$beta->id], $prefs['hidden']);

            Livewire::actingAs($user)
                ->test('pages::dashboard')
                ->call('showTableSite', $beta->id)
                ->call('resetTableLayout');

            $this->assertNull($user->fresh()->dashboard_table);
        }
    }

    #[Test]
    public function dashboard_explains_app_failure_when_the_linked_proxy_is_up(): void
    {
        $proxy = MonitorTarget::factory()->proxy()->create([
            'name' => 'Nginx Entrega',
            'last_ok' => true,
            'last_status_code' => 200,
        ]);
        MonitorTarget::factory()->create([
            'name' => 'Portal Entrega',
            'proxy_target_id' => $proxy->id,
            'last_ok' => false,
            'last_status_code' => 502,
        ]);

        $this->mock(FastApiProbe::class, function ($mock): void {
            $mock->shouldReceive('enabled')->andReturn(false);
            $mock->shouldReceive('engineLabel')->andReturn('php-curl');
            $mock->shouldReceive('runtime')->andReturn($this->disabledRuntime());
        });

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Nginx Entrega')
            ->assertSee('Portal Entrega')
            ->assertSee('vía Nginx Entrega')
            ->assertSee('Falla de la aplicación');
    }

    /**
     * @return array<string, mixed>
     */
    private function disabledRuntime(): array
    {
        $down = ['ok' => false, 'label' => 'off', 'hint' => 'off'];
        $block = ['engine' => 'php-curl', 'api' => $down, 'python' => $down];

        return $block + [
            'internal' => $block,
            'external' => $block,
        ];
    }
}
