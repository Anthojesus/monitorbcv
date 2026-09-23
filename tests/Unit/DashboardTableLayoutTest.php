<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Monitoring\DashboardTableLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardTableLayoutTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_keeps_default_order_until_the_user_moves_a_row(): void
    {
        $user = User::factory()->create();
        $targets = [
            ['id' => 4, 'name' => 'A'],
            ['id' => 1, 'name' => 'B'],
            ['id' => 10, 'name' => 'C'],
        ];

        $layout = app(DashboardTableLayout::class)->partition($targets, $user);

        $this->assertSame(['A', 'B', 'C'], array_column($layout['visible'], 'name'));
        $this->assertSame([], $layout['hidden']);
    }

    #[Test]
    public function it_moves_and_hides_rows_for_that_user_only(): void
    {
        $user = User::factory()->monitor()->create();
        $other = User::factory()->admin()->create();
        $targets = [
            ['id' => 4, 'name' => 'A'],
            ['id' => 1, 'name' => 'B'],
            ['id' => 10, 'name' => 'C'],
        ];
        $layout = app(DashboardTableLayout::class);

        $layout->move($user, $targets, 4, 1);
        $layout->hide($user, $targets, 10);

        $mine = $layout->partition($targets, $user->fresh());
        $theirs = $layout->partition($targets, $other);

        $this->assertSame(['B', 'A'], array_column($mine['visible'], 'name'));
        $this->assertSame(['C'], array_column($mine['hidden'], 'name'));
        $this->assertSame(['A', 'B', 'C'], array_column($theirs['visible'], 'name'));
    }

    #[Test]
    public function new_sites_append_and_reset_clears_the_preference(): void
    {
        $user = User::factory()->create();
        $layout = app(DashboardTableLayout::class);
        $first = [
            ['id' => 1, 'name' => 'Uno'],
            ['id' => 2, 'name' => 'Dos'],
        ];

        $layout->move($user, $first, 2, -1);
        $layout->hide($user, $first, 1);

        $withNew = [
            ['id' => 3, 'name' => 'Nuevo'],
            ['id' => 1, 'name' => 'Uno'],
            ['id' => 2, 'name' => 'Dos'],
        ];
        $partition = $layout->partition($withNew, $user->fresh());

        $this->assertSame(['Dos', 'Nuevo'], array_column($partition['visible'], 'name'));
        $this->assertSame(['Uno'], array_column($partition['hidden'], 'name'));

        $layout->reset($user);
        $reset = $layout->partition($withNew, $user->fresh());

        $this->assertNull($user->fresh()->dashboard_table);
        $this->assertSame(['Nuevo', 'Uno', 'Dos'], array_column($reset['visible'], 'name'));
    }
}
