<?php

namespace Tests\Feature;

use App\Models\MonitorTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MonitorAvailabilityPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reachable_mode_accepts_any_http_status(): void
    {
        $target = MonitorTarget::factory()->create([
            'expected_status' => [MonitorTarget::ANY_HTTP_STATUS],
        ]);

        $this->assertTrue($target->acceptsAnyHttpStatus());
        $this->assertTrue($target->acceptsHttpStatus(200));
        $this->assertTrue($target->acceptsHttpStatus(403));
        $this->assertTrue($target->acceptsHttpStatus(500));
        $this->assertFalse($target->acceptsHttpStatus(null));
        $this->assertSame('ok', $target->httpSuccessReason(200));
        $this->assertSame('http_reachable', $target->httpSuccessReason(403));
    }

    #[Test]
    public function strict_mode_rejects_codes_outside_the_list(): void
    {
        $target = MonitorTarget::factory()->create([
            'expected_status' => [200, 301, 302],
        ]);

        $this->assertFalse($target->acceptsAnyHttpStatus());
        $this->assertTrue($target->acceptsHttpStatus(200));
        $this->assertFalse($target->acceptsHttpStatus(403));
        $this->assertSame('ok', $target->httpSuccessReason(301));
    }

    #[Test]
    public function site_form_can_save_reachable_mode(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::monitor.sites')
            ->call('create')
            ->set('name', 'Biblioteca Extra')
            ->set('url', 'https://biblioteca.extra.bcv.org.ve/cgi-win/be_alex.cgi?nombrebd=bep5')
            ->set('kind', 'http')
            ->set('availability_mode', 'reachable')
            ->set('ssh_host', '')
            ->call('save')
            ->assertHasNoErrors();

        $target = MonitorTarget::query()->where('name', 'Biblioteca Extra')->firstOrFail();

        $this->assertTrue($target->acceptsAnyHttpStatus());
        $this->assertSame([MonitorTarget::ANY_HTTP_STATUS], $target->expected_status);
    }

    #[Test]
    public function site_form_can_save_custom_expected_codes(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::monitor.sites')
            ->call('create')
            ->set('name', 'Portal')
            ->set('url', 'https://www.bcv.org.ve/')
            ->set('kind', 'http')
            ->set('availability_mode', 'strict')
            ->set('expected_status_input', '200, 403, 302')
            ->set('ssh_host', '')
            ->call('save')
            ->assertHasNoErrors();

        $target = MonitorTarget::query()->where('name', 'Portal')->firstOrFail();

        $this->assertFalse($target->acceptsAnyHttpStatus());
        $this->assertTrue($target->acceptsHttpStatus(403));
        $this->assertFalse($target->acceptsHttpStatus(500));
        $this->assertSame([200, 403, 302], $target->expected_status);
    }

    #[Test]
    public function site_form_saves_required_probe_origin(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::monitor.sites')
            ->call('create')
            ->set('name', 'BCV Oficial')
            ->set('url', 'https://www.bcv.org.ve/')
            ->set('kind', 'http')
            ->set('probe_origin', 'external')
            ->set('ssh_host', '')
            ->call('save')
            ->assertHasNoErrors();

        $target = MonitorTarget::query()->where('name', 'BCV Oficial')->firstOrFail();

        $this->assertSame(MonitorTarget::ORIGIN_EXTERNAL, $target->probe_origin);
        $this->assertTrue($target->isExternalOrigin());
    }
}
