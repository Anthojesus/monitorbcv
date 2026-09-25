<?php

namespace Tests\Feature;

use App\Models\MonitorCommand;
use App\Models\MonitorCommandRun;
use App\Models\MonitorTarget;
use App\Models\User;
use App\Services\RemoteCommand\RemoteCommandException;
use App\Services\RemoteCommand\RemoteCommandRunner;
use App\Services\RemoteCommand\SshClient;
use App\Services\RemoteCommand\SshConnection;
use App\Services\RemoteCommand\SshExecResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RemoteCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ssh_password_is_encrypted_and_never_returned_to_the_form(): void
    {
        $user = User::factory()->create();
        $hostname = MonitorCommand::query()->where('slug', 'hostname')->firstOrFail();

        Livewire::actingAs($user)
            ->test('pages::monitor.sites')
            ->call('create')
            ->set('name', 'OCPP WS')
            ->set('url', 'https://ocppws.extra.bcv.org.ve/OCPP/health')
            ->set('kind', 'health')
            ->set('ssh_host', 'ocppws.extra.bcv.org.ve')
            ->set('ssh_port', 22)
            ->set('ssh_username', 'ops')
            ->set('ssh_password', 'super-secret-pass')
            ->set('commandIds', [$hostname->id])
            ->call('save')
            ->assertHasNoErrors();

        $target = MonitorTarget::query()->where('name', 'OCPP WS')->firstOrFail();
        $ciphertext = DB::table('monitor_target_servers')->where('monitor_target_id', $target->id)->value('password');

        $this->assertNotSame('super-secret-pass', $ciphertext);
        $this->assertSame('super-secret-pass', $target->server?->password);

        Livewire::actingAs($user)
            ->test('pages::monitor.sites')
            ->call('edit', $target->id)
            ->assertSet('ssh_password', '')
            ->assertSet('hasStoredPassword', true)
            ->assertSet('ssh_username', 'ops')
            ->assertDontSee('super-secret-pass');

        $this->actingAs($user)
            ->get(route('monitor.sites.show', $target))
            ->assertOk()
            ->assertSee('Acciones de servidor')
            ->assertSee('ops@ocppws.extra.bcv.org.ve')
            ->assertDontSee('super-secret-pass');
    }

    #[Test]
    public function query_command_runs_for_sites_and_web_services(): void
    {
        $user = User::factory()->create();

        foreach (['http', 'health'] as $kind) {
            $target = MonitorTarget::factory()->create([
                'name' => $kind === 'health' ? 'OCPP Health' : 'Portal BCV',
                'kind' => $kind,
            ]);
            $command = $this->attachSsh($target, ['hostname']);

            $this->mock(SshClient::class, function ($mock) use ($command): void {
                $mock->shouldReceive('exec')
                    ->once()
                    ->withArgs(function (SshConnection $connection, string $executed) use ($command): bool {
                        return $connection->host === 'prod.example.test'
                            && $connection->username === 'ops'
                            && $connection->password === 's3cret-pass'
                            && $executed === $command->command;
                    })
                    ->andReturn(new SshExecResult(0, "prod-host\n", '', 'fingerprint-1'));
            });

            Livewire::actingAs($user)
                ->test('pages::monitor.site', ['target' => $target])
                ->assertSee('Acciones de servidor')
                ->set('selectedCommandId', (string) $command->id)
                ->call('execute')
                ->assertHasNoErrors()
                ->assertSee('prod-host');

            $run = MonitorCommandRun::query()->where('monitor_target_id', $target->id)->firstOrFail();

            $this->assertSame('ok', $run->status);
            $this->assertSame($user->id, $run->user_id);
            $this->assertSame('query', $run->kind);
            $this->assertSame('hostname', $run->command_snapshot);
            $this->assertStringNotContainsString('s3cret-pass', (string) $run->stdout);
            $this->assertSame('fingerprint-1', $target->fresh()->server?->host_fingerprint);
        }
    }

    #[Test]
    public function command_output_can_be_cleared_and_returns_after_the_next_run(): void
    {
        $user = User::factory()->create();
        $target = MonitorTarget::factory()->create(['name' => 'Portal BCV']);
        $command = $this->attachSsh($target, ['hostname']);

        $this->mock(SshClient::class, function ($mock): void {
            $mock->shouldReceive('exec')
                ->twice()
                ->andReturn(
                    new SshExecResult(0, "primera-salida\n", '', 'fingerprint-1'),
                    new SshExecResult(0, "segunda-salida\n", '', 'fingerprint-1'),
                );
        });

        Livewire::actingAs($user)
            ->test('pages::monitor.site', ['target' => $target])
            ->set('selectedCommandId', (string) $command->id)
            ->call('execute')
            ->assertSee('primera-salida')
            ->call('clearCommandOutput')
            ->assertDontSee('primera-salida')
            ->assertSee('Salida limpia')
            ->call('execute')
            ->assertSee('segunda-salida')
            ->assertDontSee('primera-salida');
    }

    #[Test]
    public function change_command_requires_the_exact_site_name(): void
    {
        $user = User::factory()->create();
        $target = MonitorTarget::factory()->create(['name' => 'OCPP WS']);
        $command = $this->attachSsh($target, ['nginx-restart']);

        $this->mock(SshClient::class, function ($mock): void {
            $mock->shouldReceive('exec')->never();
        });

        Livewire::actingAs($user)
            ->test('pages::monitor.site', ['target' => $target])
            ->set('selectedCommandId', (string) $command->id)
            ->set('changeConfirmation', 'nombre incorrecto')
            ->call('execute');

        $this->assertSame(0, MonitorCommandRun::query()->count());

        $this->mock(SshClient::class, function ($mock) use ($command): void {
            $mock->shouldReceive('exec')
                ->once()
                ->withArgs(fn (SshConnection $connection, string $executed): bool => $executed === $command->command)
                ->andReturn(new SshExecResult(0, 'restarted', '', 'fingerprint-2'));
        });

        Livewire::actingAs($user)
            ->test('pages::monitor.site', ['target' => $target])
            ->set('selectedCommandId', (string) $command->id)
            ->set('changeConfirmation', 'OCPP WS')
            ->call('execute')
            ->assertSee('restarted');

        $this->assertSame('change', MonitorCommandRun::query()->first()?->kind);
    }

    #[Test]
    public function unassigned_commands_cannot_run(): void
    {
        $user = User::factory()->create();
        $target = MonitorTarget::factory()->create();
        $this->attachSsh($target, ['hostname']);
        $other = MonitorCommand::query()->where('slug', 'nginx-restart')->firstOrFail();

        $this->mock(SshClient::class, function ($mock): void {
            $mock->shouldReceive('exec')->never();
        });

        Livewire::actingAs($user)
            ->test('pages::monitor.site', ['target' => $target])
            ->set('selectedCommandId', (string) $other->id)
            ->set('changeConfirmation', $target->name)
            ->call('execute');

        $this->assertSame(0, MonitorCommandRun::query()->count());
    }

    #[Test]
    public function command_allowlist_rejects_shell_metacharacters(): void
    {
        $this->assertTrue(RemoteCommandRunner::commandIsSafe('systemctl status nginx'));
        $this->assertTrue(RemoteCommandRunner::commandIsSafe('journalctl -u nginx -n 80 --no-pager'));
        $this->assertTrue(RemoteCommandRunner::commandIsSafe('sudo tail -n 50 /var/log/nginx/entregaguardia-access.log'));
        $this->assertFalse(MonitorCommand::query()->where('command', 'rm -rf /')->exists());
        $this->assertFalse(RemoteCommandRunner::commandIsSafe('systemctl restart nginx; rm -rf /'));
        $this->assertFalse(RemoteCommandRunner::commandIsSafe('echo $(whoami)'));
        $this->assertFalse(RemoteCommandRunner::commandIsSafe('uname && cat /etc/shadow'));
    }

    #[Test]
    public function runner_records_auth_failures_without_leaking_the_password(): void
    {
        $user = User::factory()->create();
        $target = MonitorTarget::factory()->create(['name' => 'Portal']);
        $command = $this->attachSsh($target, ['hostname']);

        $this->mock(SshClient::class, function ($mock): void {
            $mock->shouldReceive('exec')
                ->once()
                ->andThrow(new RemoteCommandException('Usuario o clave SSH rechazados.', 'auth_failed'));
        });

        $this->expectException(RemoteCommandException::class);

        try {
            app(RemoteCommandRunner::class)->run($user, $target, $command->id);
        } finally {
            $run = MonitorCommandRun::query()->firstOrFail();
            $this->assertSame('auth_failed', $run->status);
            $this->assertStringNotContainsString('s3cret-pass', json_encode($run->toArray()) ?: '');
        }
    }

    #[Test]
    public function operators_can_add_a_server_alias_to_the_site_list_and_run_it(): void
    {
        $user = User::factory()->create();
        $target = MonitorTarget::factory()->create(['name' => 'OCPP WS', 'kind' => 'health']);
        $this->attachSsh($target, ['hostname']);

        Livewire::actingAs($user)
            ->test('pages::monitor.site', ['target' => $target])
            ->assertSee('Nuevo comando')
            ->call('openCreateCommand')
            ->assertSee('Nuevo comando o alias')
            ->set('newCommandLabel', 'Estado OCPP')
            ->set('newCommandText', 'ocpp-status')
            ->set('newCommandKind', 'query')
            ->call('addCommand')
            ->assertHasNoErrors()
            ->assertSee('ocpp-status');

        $alias = MonitorCommand::query()->where('command', 'ocpp-status')->firstOrFail();
        $this->assertTrue($alias->is_custom);
        $this->assertTrue($target->commands()->whereKey($alias->id)->exists());
        $this->assertFalse(
            RemoteCommandRunner::catalog(MonitorTarget::factory()->create()->id)
                ->contains(fn (MonitorCommand $command): bool => $command->id === $alias->id),
        );

        $this->mock(SshClient::class, function ($mock): void {
            $mock->shouldReceive('exec')
                ->once()
                ->withArgs(fn (SshConnection $connection, string $executed): bool => $executed === 'ocpp-status')
                ->andReturn(new SshExecResult(0, "OCPP alive\n", '', 'fingerprint-alias'));
        });

        Livewire::actingAs($user)
            ->test('pages::monitor.site', ['target' => $target])
            ->set('selectedCommandId', (string) $alias->id)
            ->call('execute')
            ->assertSee('OCPP alive');
    }

    #[Test]
    public function custom_commands_cannot_include_shell_metacharacters(): void
    {
        $user = User::factory()->create();
        $target = MonitorTarget::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::monitor.site', ['target' => $target])
            ->set('newCommandLabel', 'Peligroso')
            ->set('newCommandText', 'ocpp-status; rm -rf /')
            ->call('addCommand')
            ->assertHasErrors('newCommandText');

        $this->assertSame(0, MonitorCommand::query()->where('is_custom', true)->count());
    }

    #[Test]
    public function a_pending_alias_is_saved_when_creating_a_site(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::monitor.sites')
            ->call('create')
            ->set('kind', 'http')
            ->set('name', 'Portal Alias')
            ->set('url', 'https://portal.example.test')
            ->set('ssh_host', '')
            ->set('newCommandLabel', 'Cola de impresión')
            ->set('newCommandText', 'lpstat-alias')
            ->set('newCommandKind', 'query')
            ->call('addCustomCommandToForm')
            ->assertHasNoErrors()
            ->call('save')
            ->assertHasNoErrors();

        $target = MonitorTarget::query()->where('name', 'Portal Alias')->firstOrFail();
        $this->assertTrue($target->commands()->where('command', 'lpstat-alias')->exists());
    }

    #[Test]
    public function a_custom_command_can_be_edited(): void
    {
        $user = User::factory()->create();
        $target = MonitorTarget::factory()->create(['name' => 'Entrega Guardia']);
        $this->attachSsh($target, ['hostname']);

        Livewire::actingAs($user)
            ->test('pages::monitor.site', ['target' => $target])
            ->set('newCommandLabel', 'Access log')
            ->set('newCommandText', 'tail -n 50 /var/log/nginx/entregaguardia-access.log')
            ->call('addCommand')
            ->assertHasNoErrors();

        $command = MonitorCommand::query()->where('command', 'like', 'tail -n 50%')->firstOrFail();

        Livewire::actingAs($user)
            ->test('pages::monitor.site', ['target' => $target])
            ->set('selectedCommandId', (string) $command->id)
            ->call('startEditCommand')
            ->assertSet('editingCommandId', $command->id)
            ->assertSet('newCommandText', 'tail -n 50 /var/log/nginx/entregaguardia-access.log')
            ->set('newCommandText', 'sudo tail -n 50 /var/log/nginx/entregaguardia-access.log')
            ->call('addCommand')
            ->assertHasNoErrors();

        $this->assertSame(
            'sudo tail -n 50 /var/log/nginx/entregaguardia-access.log',
            $command->fresh()?->command,
        );
    }

    /**
     * @param  list<string>  $slugs
     */
    private function attachSsh(MonitorTarget $target, array $slugs): MonitorCommand
    {
        $target->server()->create([
            'host' => 'prod.example.test',
            'port' => 22,
            'username' => 'ops',
            'password' => 's3cret-pass',
        ]);

        $commands = MonitorCommand::query()->whereIn('slug', $slugs)->get();
        $target->commands()->sync($commands->pluck('id'));

        $command = $commands->first();
        $this->assertNotNull($command);

        return $command;
    }
}
