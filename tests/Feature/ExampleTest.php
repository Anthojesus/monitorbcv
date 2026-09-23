<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_is_the_monitor_gateway_for_guests(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Monitoreo de portales')
            ->assertSee('Acceso al Centro de Control')
            ->assertSee('Banco Central de Venezuela');
    }

    public function test_authenticated_users_are_sent_from_home_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertRedirect(route('dashboard'));
    }
}
