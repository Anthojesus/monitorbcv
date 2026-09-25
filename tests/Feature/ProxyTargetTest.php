<?php

namespace Tests\Feature;

use App\Models\MonitorCommand;
use App\Models\MonitorCommandRun;
use App\Models\MonitorTarget;
use App\Models\User;
use App\Services\Monitoring\ProxyCorrelation;
use App\Services\Monitoring\SiteCondition;
use App\Services\RemoteCommand\SshClient;
use App\Services\RemoteCommand\SshConnection;
use App\Services\RemoteCommand\SshExecResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProxyTargetTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function create_form_asks_for_type_before_showing_fields(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test('pages::monitor.sites')
            ->assertDontSee('¿Qué va a monitorear?')
            ->call('create')
            ->assertSee('¿Qué va a monitorear?')
            ->assertSee('Proxy Linux')
            ->assertSee('Sitio web')
            ->assertSee('Web service')
            ->assertDontSee('URL del proxy (80/443)')
            ->assertDontSee('Criterio de UP')
            ->set('kind', 'proxy')
            ->assertSee('URL del proxy (80/443)')
            ->assertSee('Servidor SSH')
            ->assertSee('Comandos del proxy')
            ->assertDontSee('Criterio de UP')
            ->assertDontSee('Método')
            ->assertDontSee('Palabra clave (opcional)')
            ->assertDontSee('Timeout (s)')
            ->set('kind', 'http')
            ->assertSee('URL HTTPS')
            ->assertSee('Criterio de UP')
            ->assertSee('Método')
            ->assertSee('Proxy que lo contiene')
            ->set('kind', 'health')
            ->assertSee('URL del health')
            ->assertSee('Proxy que lo contiene')
            ->assertDontSee('Criterio de UP')
            ->assertDontSee('Método');
    }

    #[Test]
    public function admin_can_create_a_linux_proxy_and_link_a_site(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test('pages::monitor.sites')
            ->call('create')
            ->set('name', 'Nginx Entrega')
            ->set('url', 'http://172.24.28.130/')
            ->set('kind', 'proxy')
            ->set('availability_mode', 'reachable')
            ->set('ssh_host', '')
            ->call('save')
            ->assertHasNoErrors();

        $proxy = MonitorTarget::query()->where('name', 'Nginx Entrega')->firstOrFail();
        $this->assertTrue($proxy->isProxy());
        $this->assertTrue($proxy->acceptsAnyHttpStatus());

        Livewire::actingAs($admin)
            ->test('pages::monitor.sites')
            ->call('create')
            ->set('name', 'Portal Entrega')
            ->set('url', 'http://172.24.28.130/admin/login')
            ->set('kind', 'http')
            ->set('proxy_target_id', $proxy->id)
            ->set('ssh_host', '')
            ->call('save')
            ->assertHasNoErrors();

        $site = MonitorTarget::query()->where('name', 'Portal Entrega')->firstOrFail();
        $this->assertSame($proxy->id, $site->proxy_target_id);
        $this->assertSame('Nginx Entrega', $site->proxy?->name);
    }

    #[Test]
    public function site_cannot_use_itself_or_a_non_proxy_as_front(): void
    {
        $admin = User::factory()->admin()->create();
        $http = MonitorTarget::factory()->create(['name' => 'Portal']);
        $other = MonitorTarget::factory()->create(['name' => 'Otra App']);

        Livewire::actingAs($admin)
            ->test('pages::monitor.sites')
            ->call('edit', $http->id)
            ->set('proxy_target_id', $http->id)
            ->call('save')
            ->assertHasErrors(['proxy_target_id']);

        Livewire::actingAs($admin)
            ->test('pages::monitor.sites')
            ->call('edit', $http->id)
            ->set('proxy_target_id', $other->id)
            ->call('save')
            ->assertHasErrors(['proxy_target_id']);

        $this->assertNull($http->fresh()?->proxy_target_id);
    }

    #[Test]
    public function it_explains_app_down_when_proxy_is_up(): void
    {
        $proxy = MonitorTarget::factory()->proxy()->create([
            'name' => 'Nginx Front',
            'last_ok' => true,
        ]);
        $site = MonitorTarget::factory()->create([
            'name' => 'Portal',
            'proxy_target_id' => $proxy->id,
            'last_ok' => false,
        ]);

        $diagnosis = app(ProxyCorrelation::class)->diagnose(
            $site,
            $proxy,
            ['key' => 'down'],
            ['key' => 'ok'],
        );

        $this->assertSame('app_behind_proxy', $diagnosis['key'] ?? null);
        $this->assertStringContainsString('aplicación', strtolower((string) ($diagnosis['title'] ?? '')));
    }

    #[Test]
    public function it_explains_proxy_down_when_both_are_down(): void
    {
        $proxy = MonitorTarget::factory()->proxy()->create(['last_ok' => false]);
        $site = MonitorTarget::factory()->create([
            'proxy_target_id' => $proxy->id,
            'last_ok' => false,
        ]);

        $diagnosis = app(ProxyCorrelation::class)->diagnose(
            $site,
            $proxy,
            ['key' => 'down'],
            ['key' => 'down'],
        );

        $this->assertSame('proxy_down', $diagnosis['key'] ?? null);
    }

    #[Test]
    public function site_detail_shows_proxy_diagnosis(): void
    {
        $user = User::factory()->create();
        $proxy = MonitorTarget::factory()->proxy()->create([
            'name' => 'Nginx Front',
            'last_ok' => true,
        ]);
        $site = MonitorTarget::factory()->create([
            'name' => 'Portal',
            'proxy_target_id' => $proxy->id,
            'last_ok' => false,
        ]);

        Livewire::actingAs($user)
            ->test('pages::monitor.site', ['target' => $site])
            ->assertSee('Proxy Nginx Front')
            ->assertSee('Falla de la aplicación');
    }

    #[Test]
    public function proxy_condition_uses_the_same_evaluator(): void
    {
        $proxy = MonitorTarget::factory()->proxy()->create(['last_ok' => true]);

        $row = app(SiteCondition::class)->evaluate($proxy, new Collection, true);

        $this->assertSame('ok', $row['key']);
    }

    #[Test]
    public function query_command_runs_on_a_linux_proxy(): void
    {
        $user = User::factory()->create();
        $target = MonitorTarget::factory()->proxy()->create(['name' => 'Nginx Entrega']);
        $command = MonitorCommand::query()->where('slug', 'nginx-test')->firstOrFail();

        $target->server()->create([
            'host' => 'proxy.example.test',
            'port' => 22,
            'username' => 'ops',
            'password' => 's3cret-pass',
        ]);
        $target->commands()->sync([$command->id]);

        $this->mock(SshClient::class, function ($mock) use ($command): void {
            $mock->shouldReceive('exec')
                ->once()
                ->withArgs(function (SshConnection $connection, string $executed) use ($command): bool {
                    return $connection->host === 'proxy.example.test'
                        && $connection->username === 'ops'
                        && $connection->password === 's3cret-pass'
                        && $executed === $command->command;
                })
                ->andReturn(new SshExecResult(0, "nginx: configuration file ok\n", '', 'fingerprint-proxy'));
        });

        Livewire::actingAs($user)
            ->test('pages::monitor.site', ['target' => $target])
            ->assertSee('Acciones de servidor')
            ->assertSee('Proxy Linux')
            ->set('selectedCommandId', (string) $command->id)
            ->call('execute')
            ->assertHasNoErrors()
            ->assertSee('configuration file ok');

        $run = MonitorCommandRun::query()->where('monitor_target_id', $target->id)->firstOrFail();

        $this->assertSame('ok', $run->status);
        $this->assertSame('nginx -t', $run->command_snapshot);
    }
}
