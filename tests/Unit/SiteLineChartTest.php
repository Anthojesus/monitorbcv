<?php

namespace Tests\Unit;

use App\Models\MonitorCheck;
use App\Models\MonitorTarget;
use App\Services\Monitoring\SiteLineChart;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteLineChartTest extends TestCase
{
    #[Test]
    public function it_builds_one_colored_https_line_per_site(): void
    {
        $https = $this->target(1, 'Portal', 'https://portal.example.com', [
            ['total' => 120, 'net' => 20, 'ok' => true, 'minutes' => 4],
            ['total' => 180, 'net' => 40, 'ok' => false, 'minutes' => 0],
        ]);
        $http = $this->target(2, 'Intranet', 'http://intra.example.com', [
            ['total' => 50, 'net' => 10, 'ok' => true, 'minutes' => 0],
        ]);

        $series = app(SiteLineChart::class)->series(Collection::make([$https, $http]));

        $this->assertCount(1, $series);
        $this->assertSame('Portal', $series[0]['name']);
        $this->assertSame(MonitorTarget::ORIGIN_INTERNAL, $series[0]['probe_origin']);
        $this->assertSame(SiteLineChart::COLORS[1 % count(SiteLineChart::COLORS)], $series[0]['color']);
        $this->assertCount(2, $series[0]['points']);
        $this->assertSame(120, $series[0]['points'][0]['total']);
        $this->assertSame(40, $series[0]['points'][1]['net']);
        $this->assertFalse($series[0]['points'][1]['ok']);
    }

    #[Test]
    public function it_hides_sites_and_plots_real_timestamps(): void
    {
        $alpha = $this->target(3, 'Alpha', 'https://alpha.example.com', [
            ['total' => 80, 'net' => 10, 'ok' => true, 'minutes' => 2],
            ['total' => 90, 'net' => 12, 'ok' => true, 'minutes' => 0],
        ]);
        $beta = $this->target(4, 'Beta', 'https://beta.example.com', [
            ['total' => 200, 'net' => 30, 'ok' => true, 'minutes' => 1],
        ]);

        $chart = app(SiteLineChart::class);
        $series = $chart->series(Collection::make([$alpha, $beta]));
        $layout = $chart->layout($series, 'total', [$beta->id]);

        $this->assertSame(2, $layout['site_count']);
        $this->assertSame(1, $layout['visible_count']);
        $this->assertSame('total', $layout['metric']);
        $this->assertCount(1, $layout['lines']);
        $this->assertSame('Alpha', $layout['lines'][0]['name']);
        $this->assertNotSame('', $layout['lines'][0]['path']);
        $this->assertNotSame('', $layout['lines'][0]['area']);
        $this->assertSame(90, $layout['lines'][0]['label_ms']);
        $this->assertSame(1600, $layout['width']);
        $this->assertSame(100, $layout['max']);
        $this->assertNull($layout['lines'][0]['points'][0]['prev_ms']);
        $this->assertSame(80, $layout['lines'][0]['points'][1]['prev_ms']);
        $this->assertCount(2, $layout['lines'][0]['points']);
        $this->assertNotEquals(
            $layout['lines'][0]['points'][0]['x'],
            $layout['lines'][0]['points'][1]['x'],
        );
    }

    #[Test]
    public function wall_clock_window_shifts_points_left_as_time_advances(): void
    {
        $alpha = $this->target(5, 'Live', 'https://live.example.com', [
            ['total' => 100, 'net' => 20, 'ok' => true, 'minutes' => 5],
        ]);
        $chart = app(SiteLineChart::class);
        $series = $chart->series(Collection::make([$alpha]));
        $pointTs = $series[0]['points'][0]['ts'];

        $first = $chart->layout(
            $series,
            'total',
            [],
            now()->subMinutes(15),
            now(),
        );
        $later = $chart->layout(
            $series,
            'total',
            [],
            now()->subMinutes(15)->addSeconds(30),
            now()->addSeconds(30),
        );

        $this->assertLessThan($first['width'] - $first['right'], $first['lines'][0]['points'][0]['x']);
        $this->assertLessThan(
            $first['lines'][0]['points'][0]['x'],
            $later['lines'][0]['points'][0]['x'],
        );
        $this->assertSame($pointTs, $series[0]['points'][0]['ts']);
    }

    #[Test]
    public function series_skips_probe_unavailable_samples(): void
    {
        $target = $this->target(6, 'Radar', 'https://radar.example.com', [
            ['total' => 80, 'net' => 10, 'ok' => true, 'minutes' => 2],
        ]);
        $target->checks->push((new MonitorCheck)->forceFill([
            'ok' => false,
            'total_ms' => null,
            'availability_reason' => 'probe_unavailable',
            'checked_at' => now(),
            'payload' => ['probe_unavailable' => true],
        ]));

        $series = app(SiteLineChart::class)->series(Collection::make([$target]));

        $this->assertCount(1, $series[0]['points']);
        $this->assertSame(80, $series[0]['points'][0]['total']);
    }

    /**
     * @param  list<array{total: int, net: int, ok: bool, minutes: int}>  $points
     */
    private function target(int $id, string $name, string $url, array $points): MonitorTarget
    {
        $target = (new MonitorTarget)->forceFill([
            'id' => $id,
            'name' => $name,
            'url' => $url,
            'last_ok' => $points[array_key_last($points)]['ok'],
            'last_total_ms' => $points[array_key_last($points)]['total'],
        ]);

        $checks = Collection::make($points)->map(function (array $point) {
            return (new MonitorCheck)->forceFill([
                'ok' => $point['ok'],
                'total_ms' => $point['total'],
                'checked_at' => now()->subMinutes($point['minutes']),
                'payload' => [
                    'timings_ms' => [
                        'dns' => (int) ($point['net'] / 2),
                        'tcp' => (int) ceil($point['net'] / 2),
                        'total' => $point['total'],
                    ],
                ],
            ]);
        });

        $target->setRelation('checks', $checks);

        return $target;
    }
}
