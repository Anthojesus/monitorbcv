@props([
    'points' => [],
])

@php
    /** @var list<array{time: string, avg_ms: int, avg_net_ms: int}> $points */
    $width = 800;
    $height = 260;
    $left = 48;
    $right = 16;
    $top = 16;
    $bottom = 36;
    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;
    $count = count($points);
    $max = 1;
    foreach ($points as $point) {
        $max = max($max, (int) $point['avg_ms'], (int) $point['avg_net_ms']);
    }
    $max = (int) ceil($max * 1.15);
    $x = function (int $index) use ($count, $left, $plotWidth): float {
        if ($count <= 1) {
            return $left + ($plotWidth / 2);
        }

        return $left + ($index * $plotWidth / ($count - 1));
    };
    $y = function (int $value) use ($max, $top, $plotHeight): float {
        return $top + $plotHeight - (($value / $max) * $plotHeight);
    };
    $line = function (string $field) use ($points, $x, $y): string {
        $path = [];
        foreach (array_values($points) as $index => $point) {
            $path[] = ($index === 0 ? 'M' : 'L').round($x($index), 1).' '.round($y((int) $point[$field]), 1);
        }

        return implode(' ', $path);
    };
    $ticks = [0, (int) round($max / 2), $max];
@endphp

<div {{ $attributes->class('relative w-full') }}>
    @if ($count === 0)
        <div class="flex h-64 items-center justify-center rounded-lg border border-dashed border-zinc-300 text-sm text-zinc-500 dark:border-white/15">
            Aún no hay sondeos para graficar. El próximo ciclo dibuja la primera línea.
        </div>
    @else
        <svg viewBox="0 0 {{ $width }} {{ $height }}" class="h-64 w-full text-zinc-400" role="img" aria-label="Latencia de red y tiempo total">
            @foreach ($ticks as $tick)
                <line
                    x1="{{ $left }}"
                    x2="{{ $width - $right }}"
                    y1="{{ round($y($tick), 1) }}"
                    y2="{{ round($y($tick), 1) }}"
                    class="stroke-zinc-200 dark:stroke-white/10"
                    stroke-width="1"
                />
                <text x="{{ $left - 8 }}" y="{{ round($y($tick), 1) + 4 }}" text-anchor="end" class="fill-zinc-500 text-[11px]">{{ $tick }}</text>
            @endforeach

            @if ($count > 1)
                <path d="{{ $line('avg_ms') }}" fill="none" class="stroke-sky-500" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                <path d="{{ $line('avg_net_ms') }}" fill="none" class="stroke-cyan-400" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
            @endif

            @foreach (array_values($points) as $index => $point)
                <circle cx="{{ round($x($index), 1) }}" cy="{{ round($y((int) $point['avg_ms']), 1) }}" r="3.5" class="fill-sky-500" />
                <circle cx="{{ round($x($index), 1) }}" cy="{{ round($y((int) $point['avg_net_ms']), 1) }}" r="3.5" class="fill-cyan-400" />
            @endforeach

            <text x="{{ $left }}" y="{{ $height - 8 }}" class="fill-zinc-500 text-[11px]">{{ $points[0]['time'] }}</text>
            <text x="{{ $width - $right }}" y="{{ $height - 8 }}" text-anchor="end" class="fill-zinc-500 text-[11px]">{{ $points[$count - 1]['time'] }}</text>
        </svg>

        <div class="mt-2 flex flex-wrap gap-4 text-xs text-zinc-500">
            <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-sky-500"></span> Tiempo total (red + TLS + app)</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-cyan-400"></span> Latencia de red (DNS + TCP)</span>
            <span>{{ $count }} sondeos · eje Y en ms</span>
        </div>
    @endif
</div>
