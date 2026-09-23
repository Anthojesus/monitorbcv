@props([
    'series' => [],
    'metric' => 'total',
    'hidden' => [],
    'title' => 'Comparativo HTTPS en el tiempo',
    'subtitle' => 'Pasa el mouse para previsualizar. Clic en el gráfico fija el recuadro para poder desplazarte por todos los sitios. Esc o “Soltar” lo cierra.',
    'pinKey' => 'default',
    'showControls' => true,
    'windowStart' => null,
])

@php
    $hidden = array_map('intval', is_array($hidden) ? $hidden : []);
    $pinKey = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $pinKey) ?: 'default';
    $windowStart = $windowStart instanceof \DateTimeInterface ? $windowStart : null;
    $chart = app(\App\Services\Monitoring\SiteLineChart::class)->layout($series, $metric, $hidden, $windowStart);
    $metricLabel = $metric === 'net' ? 'Latencia de red (DNS + TCP)' : 'Tiempo total (red + TLS + app)';
    $chartJson = json_encode([
        'width' => $chart['width'],
        'left' => $chart['left'],
        'plot_width' => $chart['plot_width'],
        'lines' => $chart['lines'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp

<style>
    [x-cloak] { display: none !important; }
</style>

<div class="space-y-4">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div class="max-w-3xl">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="sm">{{ $title }}</flux:heading>
                @if ($windowStart)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-300">
                        <span class="size-1.5 animate-pulse rounded-full bg-emerald-500"></span>
                        Ahora · {{ $chart['end'] }}
                    </span>
                @endif
            </div>
            <flux:text size="sm">{{ $subtitle }}</flux:text>
        </div>
        @if ($showControls)
            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex rounded-lg border border-zinc-200 p-0.5 dark:border-white/10">
                    <flux:button size="sm" :variant="$metric === 'total' ? 'primary' : 'ghost'" wire:click="$set('chartMetric', 'total')">
                        Tiempo total
                    </flux:button>
                    <flux:button size="sm" :variant="$metric === 'net' ? 'primary' : 'ghost'" wire:click="$set('chartMetric', 'net')">
                        Solo red
                    </flux:button>
                </div>
                @if ($hidden !== [])
                    <flux:button size="sm" variant="ghost" wire:click="showAllChartSites">Mostrar todos</flux:button>
                @endif
                <flux:badge>{{ $metricLabel }}</flux:badge>
            </div>
        @endif
    </div>

    @if ($chart['site_count'] === 0)
        <div class="flex h-80 items-center justify-center rounded-xl border border-dashed border-zinc-300 text-sm text-zinc-500 dark:border-white/15">
            No hay sondeos HTTPS para graficar. Añade un sitio y espera el primer ciclo.
        </div>
    @else
        @if ($chart['visible_count'] === 0)
            <div class="flex h-72 flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-zinc-300 text-sm text-zinc-500 dark:border-white/15">
                <p>Todos los sitios están ocultos. Vuelve a mostrar al menos uno para comparar.</p>
                <flux:button size="sm" wire:click="showAllChartSites">Mostrar todos</flux:button>
            </div>
        @else
            <div
                class="relative w-full"
                wire:key="chart-{{ $pinKey }}-{{ $metric }}-{{ $chart['end'] }}-{{ $chart['visible_count'] }}"
                x-data="{
                    hover: window['__monitorChartPin_{{ $pinKey }}']?.hover ?? null,
                    pinned: window['__monitorChartPin_{{ $pinKey }}']?.pinned ?? false,
                    save() {
                        window['__monitorChartPin_{{ $pinKey }}'] = { hover: this.hover, pinned: this.pinned }
                    },
                    fmt(ms) {
                        if (ms === null || ms === undefined) return '—'
                        return new Intl.NumberFormat('es-VE').format(ms) + ' ms'
                    },
                    delta(row) {
                        if (row.prev === null || row.prev === undefined || row.ms === null) return 'primer valor'
                        const diff = row.ms - row.prev
                        if (diff === 0) return 'igual que el anterior'
                        const sign = diff > 0 ? '+' : '−'
                        return sign + new Intl.NumberFormat('es-VE').format(Math.abs(diff)) + ' ms vs anterior'
                    },
                    read() {
                        return JSON.parse(this.$refs.payload.textContent)
                    },
                    snapshot(event) {
                        const box = event.currentTarget.getBoundingClientRect()
                        const data = this.read()
                        const x = ((event.clientX - box.left) / box.width) * data.width
                        let nearestX = null
                        let distance = Infinity
                        data.lines.forEach((line) => {
                            line.points.forEach((point) => {
                                const delta = Math.abs(point.x - x)
                                if (delta < distance) {
                                    distance = delta
                                    nearestX = point.x
                                }
                            })
                        })
                        if (nearestX === null) {
                            return null
                        }
                        const rows = data.lines.map((line) => {
                            let best = null
                            let bestDelta = Infinity
                            line.points.forEach((point) => {
                                const delta = Math.abs(point.x - nearestX)
                                if (delta < bestDelta) {
                                    bestDelta = delta
                                    best = point
                                }
                            })
                            return {
                                id: line.id,
                                name: line.name,
                                url: line.url,
                                color: line.color,
                                ms: best?.ms ?? null,
                                prev: best?.prev_ms ?? null,
                                ok: best?.ok ?? null,
                                time: best?.time ?? '',
                                x: best?.x,
                                y: best?.y,
                            }
                        }).sort((a, b) => (b.ms ?? -1) - (a.ms ?? -1))
                        const left = (nearestX / data.width) * 100
                        return {
                            x: nearestX,
                            left,
                            flip: left > 62,
                            time: rows.find((row) => row.time)?.time ?? '',
                            dots: rows.filter((row) => row.x != null && row.y != null),
                            rows,
                            slowest: rows[0]?.name ?? '',
                        }
                    },
                    move(event) {
                        if (this.pinned) return
                        this.hover = this.snapshot(event)
                        this.save()
                    },
                    pin(event) {
                        this.hover = this.snapshot(event) ?? this.hover
                        this.pinned = this.hover !== null
                        this.save()
                    },
                    unpin() {
                        this.pinned = false
                        this.hover = null
                        this.save()
                    },
                    leave() {
                        if (this.pinned) return
                        this.hover = null
                        this.save()
                    }
                }"
                x-on:keydown.escape.window="unpin()"
                x-on:click.outside="if (pinned) unpin()"
            >
                <div class="hidden" x-ref="payload">{!! $chartJson !!}</div>

                <svg
                    viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}"
                    class="block h-auto w-full"
                    preserveAspectRatio="xMidYMid meet"
                    role="img"
                    aria-label="{{ $title }}"
                >
                    <rect
                        x="{{ $chart['left'] }}"
                        y="{{ $chart['top'] }}"
                        width="{{ $chart['plot_width'] }}"
                        height="{{ $chart['plot_bottom'] - $chart['top'] }}"
                        class="fill-zinc-50/90 dark:fill-white/[0.035]"
                        rx="10"
                    />

                    <text x="{{ $chart['left'] - 8 }}" y="{{ $chart['top'] - 4 }}" text-anchor="end" class="fill-zinc-400 text-[11px]">ms</text>

                    @foreach ($chart['ticks'] as $tick)
                        <line
                            x1="{{ $chart['left'] }}"
                            x2="{{ $chart['width'] - $chart['right'] }}"
                            y1="{{ round($chart['y'][$tick], 1) }}"
                            y2="{{ round($chart['y'][$tick], 1) }}"
                            class="stroke-zinc-200 dark:stroke-white/10"
                        />
                        <text x="{{ $chart['left'] - 8 }}" y="{{ round($chart['y'][$tick], 1) + 4 }}" text-anchor="end" class="fill-zinc-500 text-[11px]">{{ number_format($tick, 0, ',', '.') }}</text>
                    @endforeach

                    @foreach ($chart['lines'] as $line)
                        @if ($line['area'] !== '')
                            <path d="{{ $line['area'] }}" fill="{{ $line['color'] }}" opacity="0.08" style="transition: d 0.8s linear" />
                        @endif
                        @if ($line['path'] !== '')
                            <path d="{{ $line['path'] }}" fill="none" stroke="{{ $line['color'] }}" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" opacity="0.95" style="transition: d 0.8s linear" />
                        @endif
                        @foreach ($line['points'] as $point)
                            <circle
                                cx="{{ $point['x'] }}"
                                cy="{{ $point['y'] }}"
                                r="{{ $point['ok'] ? 3.2 : 4.4 }}"
                                style="transition: cx 0.8s linear, cy 0.45s ease"
                                fill="{{ $point['ok'] ? $line['color'] : '#f43f5e' }}"
                                stroke="{{ $point['ok'] ? 'transparent' : '#fff' }}"
                                stroke-width="1.2"
                            />
                        @endforeach
                    @endforeach

                    @foreach ($chart['x_labels'] as $label)
                        @if ($label['time'])
                            <text
                                x="{{ $label['x'] }}"
                                y="{{ $chart['height'] - 8 }}"
                                text-anchor="{{ $label['anchor'] }}"
                                class="fill-zinc-500 text-[11px]"
                            >{{ $label['time'] }}</text>
                        @endif
                    @endforeach
                </svg>

                <div
                    class="absolute inset-0 z-10 cursor-crosshair"
                    x-on:mousemove="move($event)"
                    x-on:mouseleave="leave()"
                    x-on:click="pin($event)"
                ></div>

                <div
                    x-cloak
                    x-show="hover"
                    class="pointer-events-none absolute top-3 bottom-8 z-20 w-px bg-zinc-500/70 dark:bg-white/50"
                    :style="hover ? `left: ${hover.left}%` : ''"
                ></div>

                <template x-if="hover">
                    <div class="pointer-events-none absolute inset-0 z-20">
                        <template x-for="dot in hover.dots" :key="dot.id">
                            <div
                                class="absolute size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white dark:border-zinc-900"
                                :style="`left: ${(dot.x / {{ $chart['width'] }}) * 100}%; top: ${(dot.y / {{ $chart['height'] }}) * 100}%; background: ${dot.color}`"
                            ></div>
                        </template>
                    </div>
                </template>

                <div
                    x-cloak
                    x-show="hover"
                    x-on:click.stop
                    x-on:mousedown.stop
                    class="absolute top-4 z-30 flex max-h-[min(28rem,calc(100%-1.5rem))] w-[min(32rem,calc(100%-1.5rem))] flex-col rounded-xl border bg-white/97 text-xs shadow-2xl backdrop-blur dark:bg-zinc-950/97"
                    :class="pinned ? 'pointer-events-auto border-sky-400 ring-2 ring-sky-400/30 dark:border-sky-400' : 'pointer-events-none border-zinc-200 dark:border-white/10'"
                    :style="hover ? (hover.flip ? `right: ${100 - hover.left}%; left: auto; transform: translateX(-12px)` : `left: ${hover.left}%; transform: translateX(12px)`) : ''"
                >
                    <div class="flex items-start justify-between gap-3 px-3 pt-3">
                        <div>
                            <div class="text-[10px] uppercase tracking-wide text-zinc-500">Sondeo</div>
                            <div class="font-semibold text-zinc-900 dark:text-zinc-100" x-text="hover ? hover.time : ''"></div>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="text-right text-[11px] text-zinc-500" x-text="hover ? (hover.rows.length + ' sitios') : ''"></div>
                            <button
                                type="button"
                                x-show="pinned"
                                x-on:click.stop="unpin()"
                                class="rounded-md bg-zinc-100 px-2 py-1 text-[11px] font-medium text-zinc-700 hover:bg-zinc-200 dark:bg-white/10 dark:text-zinc-100 dark:hover:bg-white/15"
                            >
                                Soltar
                            </button>
                        </div>
                    </div>
                    <p class="px-3 pt-1 text-[11px] text-zinc-500" x-text="pinned ? 'Fijado. Desplázate la lista. Esc o Soltar para cerrar.' : 'Clic en el gráfico para fijar y recorrer todos los sitios.'"></p>
                    <div
                        class="min-h-0 flex-1 space-y-2 overflow-y-auto overscroll-contain px-3 py-2"
                        :class="pinned ? 'max-h-80' : 'max-h-56'"
                        x-on:wheel.stop
                    >
                        <template x-for="row in (hover ? hover.rows : [])" :key="row.id">
                            <div class="rounded-lg bg-zinc-50 px-2 py-1.5 dark:bg-white/5">
                                <div class="flex items-start justify-between gap-3">
                                    <span class="flex min-w-0 flex-1 items-start gap-2">
                                        <span class="mt-1 size-2.5 shrink-0 rounded-full" :style="'background:' + row.color"></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-medium text-zinc-800 dark:text-zinc-100" x-text="row.name"></span>
                                            <span class="block break-all font-mono text-[10px] leading-4 text-zinc-500" x-text="row.url"></span>
                                        </span>
                                    </span>
                                    <span class="shrink-0 text-right">
                                        <span class="block font-mono text-sm font-semibold" :class="row.ok === false ? 'text-rose-500' : 'text-zinc-800 dark:text-zinc-100'" x-text="fmt(row.ms)"></span>
                                        <span class="inline-flex rounded px-1 text-[10px] font-bold text-white" :class="row.ok === false ? 'bg-rose-500' : 'bg-emerald-500'" x-text="row.ok === false ? 'DOWN' : 'UP'"></span>
                                    </span>
                                </div>
                                <div class="mt-1 pl-4 text-[11px] text-zinc-500" x-text="delta(row)"></div>
                            </div>
                        </template>
                    </div>
                    <div class="border-t border-zinc-200 px-3 py-2 text-[11px] text-zinc-500 dark:border-white/10">
                        Más lento ahora: <span class="font-medium text-zinc-700 dark:text-zinc-200" x-text="hover ? hover.slowest : ''"></span>
                    </div>
                </div>
            </div>
        @endif

        <div class="flex flex-wrap gap-2">
            @foreach ($series as $site)
                @php $isHidden = in_array((int) $site['id'], $hidden, true); @endphp
                <div
                    @class([
                        'inline-flex items-center gap-1 rounded-full border py-0.5 pl-2.5 pr-1 text-xs transition',
                        'border-zinc-200 bg-white text-zinc-700 dark:border-white/10 dark:bg-white/5 dark:text-zinc-200' => ! $isHidden,
                        'border-dashed border-zinc-300 text-zinc-400 dark:border-white/10' => $isHidden,
                    ])
                >
                    <button
                        type="button"
                        class="inline-flex max-w-64 items-center gap-2"
                        wire:click="toggleChartSite({{ (int) $site['id'] }})"
                        title="Clic para {{ $isHidden ? 'mostrar' : 'ocultar' }} este sitio"
                    >
                        <span class="size-2.5 rounded-full" style="background: {{ $isHidden ? '#a1a1aa' : $site['color'] }}"></span>
                        <span @class(['max-w-40 truncate font-medium', 'line-through' => $isHidden])>{{ $site['name'] }}</span>
                        <span class="font-mono text-[10px] text-zinc-500">
                            {{ $metric === 'net' ? ($site['latest_net'] ?? '—') : ($site['latest_ms'] ?? '—') }} ms
                        </span>
                        @if ($site['ok'] === false)
                            <span class="rounded bg-rose-500 px-1 font-bold text-white">DOWN</span>
                        @endif
                    </button>
                    <button
                        type="button"
                        class="rounded-full px-1.5 py-0.5 text-[10px] font-medium text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:hover:bg-white/10 dark:hover:text-zinc-100"
                        wire:click="soloChartSite({{ (int) $site['id'] }})"
                        title="Ver solo este sitio"
                    >
                        Solo
                    </button>
                </div>
            @endforeach
        </div>

        <p class="text-[11px] leading-4 text-zinc-500">
            {{ $chart['visible_count'] }} de {{ $chart['site_count'] }} sitios visibles
            · el último valor está en cada pastilla
            · puntos rojos = DOWN
            · el eje X avanza con el reloj (las líneas se mueven a la izquierda)
            · clic en el gráfico fija el recuadro para hacer scroll
            · Esc o “Soltar” lo cierra.
        </p>
    @endif
</div>
