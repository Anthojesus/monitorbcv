<?php

use App\Services\Monitoring\ChartRange;
use App\Services\Monitoring\DashboardMetrics;
use App\Services\Monitoring\DashboardTableLayout;
use App\Services\Monitoring\MonitorEngine;
use App\Services\Monitoring\MonitorSettings;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Centro de Control')] class extends Component
{
    public string $chartMetric = 'total';

    public string $chartRange = ChartRange::DEFAULT;

    /**
     * @var list<int>
     */
    public array $hiddenChartIds = [];

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function metrics(): array
    {
        return app(DashboardMetrics::class)->all($this->chartSince());
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function chartRangeOptions(): array
    {
        return ChartRange::options(app(MonitorSettings::class)->chartHistoryMaxDays());
    }

    public function updatedChartRange(): void
    {
        unset($this->metrics);
    }

    public function tick(MonitorEngine $engine): void
    {
        $engine->runDue();
        unset($this->metrics, $this->tableLayout);
    }

    public function toggleChartSite(int $id): void
    {
        $hidden = array_map('intval', $this->hiddenChartIds);

        $this->hiddenChartIds = in_array($id, $hidden, true)
            ? array_values(array_filter($hidden, fn (int $hiddenId) => $hiddenId !== $id))
            : [...$hidden, $id];
    }

    public function soloChartSite(int $id): void
    {
        $ids = collect($this->metrics['http']['site_series'] ?? [])
            ->pluck('id')
            ->map(fn ($siteId) => (int) $siteId)
            ->all();

        $this->hiddenChartIds = array_values(array_filter($ids, fn (int $siteId) => $siteId !== $id));
    }

    public function showAllChartSites(): void
    {
        $this->hiddenChartIds = [];
    }

    /**
     * @return array{visible: list<array<string, mixed>>, hidden: list<array<string, mixed>>}
     */
    #[Computed]
    public function tableLayout(): array
    {
        return app(DashboardTableLayout::class)->partition(
            $this->metrics['http']['targets'] ?? [],
            auth()->user(),
        );
    }

    public function moveTableSiteUp(int $id): void
    {
        $this->moveTableSite($id, -1);
    }

    public function moveTableSiteDown(int $id): void
    {
        $this->moveTableSite($id, 1);
    }

    public function hideTableSite(int $id): void
    {
        app(DashboardTableLayout::class)->hide(auth()->user(), $this->metrics['http']['targets'] ?? [], $id);
        unset($this->tableLayout);
    }

    public function showTableSite(int $id): void
    {
        app(DashboardTableLayout::class)->show(auth()->user(), $this->metrics['http']['targets'] ?? [], $id);
        unset($this->tableLayout);
    }

    public function resetTableLayout(): void
    {
        app(DashboardTableLayout::class)->reset(auth()->user());
        unset($this->tableLayout);
    }

    private function moveTableSite(int $id, int $delta): void
    {
        app(DashboardTableLayout::class)->move(auth()->user(), $this->metrics['http']['targets'] ?? [], $id, $delta);
        unset($this->tableLayout);
    }

    public function chartWindowStart(): \Carbon\CarbonInterface
    {
        return $this->chartSince();
    }

    private function chartSince(): \Carbon\CarbonInterface
    {
        $maxDays = app(MonitorSettings::class)->chartHistoryMaxDays();
        $this->chartRange = ChartRange::normalize($this->chartRange, $maxDays);

        return ChartRange::since($this->chartRange, $maxDays);
    }
}; ?>

@php
    $http = $this->metrics['http'];
    $table = $this->tableLayout;
    $hasCustomTable = filled(auth()->user()?->dashboard_table);
    $health = $http['health'];
    $healthCopy = match ($health) {
        'critical' => ['Sitios con incidencia', 'Hay destinos DOWN. Prioriza esos chequeos ahora.', 'rose', 'x-circle'],
        'attention' => ['Revisión recomendada', 'Hay degradación, SSL por vencer o un desfase Interior/Exterior.', 'amber', 'exclamation-triangle'],
        'empty' => ['Sin destinos', 'Añade la primera URL para encender el radar.', 'zinc', 'plus-circle'],
        default => ['Todo operativo', 'Los destinos monitoreados responden dentro de umbral.', 'lime', 'check-circle'],
    };
@endphp

<section class="flex w-full flex-col gap-6" wire:poll.5s="tick">
    <div class="relative overflow-hidden rounded-2xl border border-white/10 bg-zinc-950 px-6 py-6 text-white shadow-xl dark:bg-black/40 sm:px-8">
        <div class="pointer-events-none absolute -right-16 -top-20 size-64 rounded-full bg-sky-500/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-24 left-1/3 size-56 rounded-full bg-emerald-400/10 blur-3xl"></div>

        <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-2xl space-y-3">
                <div class="flex flex-wrap items-center gap-2.5">
                    <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-semibold tracking-wide text-white uppercase">
                        <span @class([
                            'size-2.5 rounded-full',
                            'bg-rose-500 shadow-[0_0_12px] shadow-rose-400 animate-pulse' => $health === 'critical',
                            'bg-amber-400 shadow-[0_0_12px] shadow-amber-300 animate-pulse' => $health === 'attention',
                            'bg-zinc-400' => $health === 'empty',
                            'bg-emerald-400 shadow-[0_0_14px] shadow-emerald-300 animate-pulse' => $health === 'operational',
                        ])></span>
                        Centro de Control
                    </span>
                    @php
                        $runtime = $this->metrics['runtime'];
                        $internalRt = $runtime['internal'] ?? $runtime;
                        $externalRt = $runtime['external'] ?? ['api' => ['ok' => false, 'hint' => 'Sin sonda exterior'], 'python' => ['ok' => false, 'hint' => 'Sin sonda exterior']];
                        $probeGroups = [
                            [
                                'label' => 'Interior',
                                'items' => [
                                    ['name' => 'API Interior', 'ok' => (bool) ($internalRt['api']['ok'] ?? false), 'hint' => $internalRt['api']['hint'] ?? ''],
                                    ['name' => 'Sonda Interior', 'ok' => (bool) ($internalRt['python']['ok'] ?? false), 'hint' => $internalRt['python']['hint'] ?? ''],
                                ],
                            ],
                            [
                                'label' => 'Exterior',
                                'items' => [
                                    ['name' => 'API Exterior', 'ok' => (bool) ($externalRt['api']['ok'] ?? false), 'hint' => $externalRt['api']['hint'] ?? ''],
                                    ['name' => 'Sonda Exterior', 'ok' => (bool) ($externalRt['python']['ok'] ?? false), 'hint' => $externalRt['python']['hint'] ?? ''],
                                ],
                            ],
                        ];
                    @endphp
                    @foreach ($probeGroups as $group)
                        <div class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-black/30 p-1 pl-2.5">
                            <span class="pr-0.5 text-[10px] font-semibold tracking-wider text-zinc-400 uppercase">{{ $group['label'] }}</span>
                            @foreach ($group['items'] as $chip)
                                <span
                                    title="{{ $chip['hint'] }}"
                                    @class([
                                        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold tracking-wide',
                                        'bg-emerald-500 text-white shadow-[0_0_18px] shadow-emerald-400/80' => $chip['ok'],
                                        'bg-rose-600 text-white shadow-[0_0_14px] shadow-rose-500/60' => ! $chip['ok'],
                                    ])
                                >
                                    <span @class([
                                        'size-1.5 rounded-full',
                                        'bg-white animate-pulse' => $chip['ok'],
                                        'bg-rose-200' => ! $chip['ok'],
                                    ])></span>
                                    {{ $chip['name'] }} · {{ $chip['ok'] ? 'OK' : 'DOWN' }}
                                </span>
                            @endforeach
                        </div>
                    @endforeach
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500 px-2.5 py-1.5 text-xs font-bold tracking-wide text-white shadow-[0_0_18px] shadow-emerald-400/80">
                        <span class="size-1.5 rounded-full bg-white animate-pulse"></span>
                        Intervalo por sitio
                    </span>
                </div>

                <flux:heading size="xl" class="text-white">{{ $healthCopy[0] }}</flux:heading>
                <p class="text-sm leading-6 text-zinc-300">{{ $healthCopy[1] }} Último barrido {{ $http['last_run'] }}.</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3">
                    <div class="text-[11px] uppercase tracking-wider text-zinc-400">Fiabilidad global</div>
                    <div class="mt-1 font-mono text-2xl font-semibold">{{ $http['success_pct'] }}%</div>
                    <div class="mt-1 text-[11px] text-zinc-400">{{ $http['success_ok'] }} de {{ $http['success_total'] }} chequeos OK</div>
                </div>
                <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3">
                    <div class="text-[11px] uppercase tracking-wider text-zinc-400">Destinos ahora</div>
                    <div class="mt-1 font-mono text-2xl font-semibold">{{ $http['up'] }}/{{ $http['total'] }}</div>
                    <div class="mt-1 text-[11px] text-zinc-400">
                        Interior {{ $http['by_origin']['internal']['up'] ?? 0 }}/{{ $http['by_origin']['internal']['total'] ?? 0 }} UP
                        · Exterior {{ $http['by_origin']['external']['up'] ?? 0 }}/{{ $http['by_origin']['external']['total'] ?? 0 }} UP
                    </div>
                </div>
                <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3">
                    <div class="text-[11px] uppercase tracking-wider text-zinc-400">Latencia de red</div>
                    <div class="mt-1 font-mono text-2xl font-semibold">{{ number_format($http['avg_net_ms']) }}<span class="text-base font-medium text-zinc-400"> ms</span></div>
                    <div class="mt-1 text-[11px] text-zinc-400">DNS + TCP · {{ $http['avg_net_label'] }}</div>
                </div>
                <flux:button href="{{ route('monitor.sites') }}" icon="plus" variant="primary" wire:navigate>
                    Añadir sitio
                </flux:button>
            </div>
        </div>
    </div>

    @if (($http['stale_origins'] ?? []) !== [])
        <flux:callout icon="exclamation-triangle" variant="warning">
            No se está monitoreando {{ implode(' y ', $http['stale_origins']) }}: el servicio de sonda está caído o apagado. No es una falla de esos portales. El último UP/DOWN se conserva. El punto rojo global sigue siendo un portal DOWN, no una sonda caída.
        </flux:callout>
    @endif

    @if (($http['diagnoses'] ?? []) !== [])
        <flux:card class="space-y-3">
            <div>
                <flux:heading size="sm">Qué está fallando</flux:heading>
                <flux:text size="sm">Misma URL vista por Interior y Exterior. La diferencia dice si el problema es red o la app.</flux:text>
            </div>
            <div class="grid gap-3 lg:grid-cols-2">
                @foreach ($http['diagnoses'] as $diagnosis)
                    <a href="{{ route('monitor.sites.show', $diagnosis['internal_id']) }}" class="rounded-xl border px-4 py-3 transition hover:bg-zinc-50 dark:hover:bg-white/5 {{ ($diagnosis['tone'] ?? '') === 'rose' ? 'border-rose-500/30' : 'border-amber-500/30' }}" wire:navigate>
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge :color="($diagnosis['tone'] ?? '') === 'rose' ? 'rose' : 'amber'">{{ $diagnosis['title'] }}</flux:badge>
                            <span class="truncate text-sm font-medium">{{ $diagnosis['internal_name'] }}</span>
                        </div>
                        <p class="mt-1.5 text-sm text-pretty text-zinc-600 dark:text-zinc-300">{{ $diagnosis['detail'] }}</p>
                        <p class="mt-1 truncate font-mono text-[11px] text-zinc-500">{{ $diagnosis['url'] }}</p>
                    </a>
                @endforeach
            </div>
        </flux:card>
    @endif

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-4 dark:bg-emerald-500/10">
            <div class="flex items-center justify-between text-xs font-medium text-emerald-700 dark:text-emerald-300">
                <span>Operativos</span>
                <flux:icon.signal class="size-4" />
            </div>
            <div class="mt-3 text-3xl font-semibold tracking-tight text-emerald-600 dark:text-emerald-300">{{ $http['up'] }}</div>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Interior {{ $http['by_origin']['internal']['up'] ?? 0 }} · Exterior {{ $http['by_origin']['external']['up'] ?? 0 }}
            </p>
        </div>
        <div class="rounded-xl border border-rose-500/20 bg-rose-500/5 p-4 dark:bg-rose-500/10">
            <div class="flex items-center justify-between text-xs font-medium text-rose-700 dark:text-rose-300">
                <span>Caídos</span>
                <flux:icon.x-circle class="size-4" />
            </div>
            <div class="mt-3 text-3xl font-semibold tracking-tight text-rose-600 dark:text-rose-300">{{ $http['down'] }}</div>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Interior {{ $http['by_origin']['internal']['down'] ?? 0 }} · Exterior {{ $http['by_origin']['external']['down'] ?? 0 }}
            </p>
        </div>
        <div class="rounded-xl border border-amber-500/20 bg-amber-500/5 p-4 dark:bg-amber-500/10">
            <div class="flex items-center justify-between text-xs font-medium text-amber-700 dark:text-amber-300">
                <span>Degradados</span>
                <flux:icon.exclamation-triangle class="size-4" />
            </div>
            <div class="mt-3 text-3xl font-semibold tracking-tight text-amber-600 dark:text-amber-300">{{ $http['degraded'] ?? 0 }}</div>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">UP, pero TTFB, 5xx o SSL fuera de umbral</p>
        </div>
        <div class="rounded-xl border border-sky-500/20 bg-sky-500/5 p-4 dark:bg-sky-500/10">
            <div class="flex items-center justify-between text-xs font-medium text-sky-700 dark:text-sky-300">
                <span>Latencia de red</span>
                <flux:icon.signal class="size-4" />
            </div>
            <div class="mt-3 text-3xl font-semibold tracking-tight text-sky-600 dark:text-sky-300">{{ number_format($http['avg_net_ms']) }}<span class="text-base font-medium text-zinc-400"> ms</span></div>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">DNS + TCP hasta el host · {{ $http['avg_net_label'] }}</p>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
            <div class="flex items-center justify-between text-xs font-medium text-zinc-500">
                <span>Tiempo total</span>
                <flux:icon.bolt class="size-4" />
            </div>
            <div class="mt-3 text-3xl font-semibold tracking-tight">{{ number_format($http['avg_ms']) }}<span class="text-base font-medium text-zinc-400"> ms</span></div>
            <p class="mt-1 text-xs text-zinc-500">
                Promedio
                · p95 {{ $http['p95_total'] !== null ? number_format($http['p95_total']).' ms' : '—' }}
                · p99 {{ $http['p99_total'] !== null ? number_format($http['p99_total']).' ms' : '—' }}
            </p>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
            <div class="flex items-center justify-between text-xs font-medium text-zinc-500">
                <span>TTFB</span>
                <flux:icon.clock class="size-4" />
            </div>
            <div class="mt-3 text-3xl font-semibold tracking-tight">{{ number_format($http['avg_ttfb']) }}<span class="text-base font-medium text-zinc-400"> ms</span></div>
            <p class="mt-1 text-xs text-zinc-500">
                Promedio
                · p95 {{ $http['p95_ttfb'] !== null ? number_format($http['p95_ttfb']).' ms' : '—' }}
                · p99 {{ $http['p99_ttfb'] !== null ? number_format($http['p99_ttfb']).' ms' : '—' }}
            </p>
        </div>
        <div @class([
            'rounded-xl border p-4',
            'border-amber-500/30 bg-amber-500/5 dark:bg-amber-500/10' => $http['ssl_expiring'] > 0,
            'border-zinc-200 bg-white dark:border-white/10 dark:bg-white/5' => $http['ssl_expiring'] === 0,
        ])>
            <div class="flex items-center justify-between text-xs font-medium {{ $http['ssl_expiring'] > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-zinc-500' }}">
                <span>SSL por vencer</span>
                <flux:icon.shield-check class="size-4" />
            </div>
            <div class="mt-3 text-3xl font-semibold tracking-tight">{{ $http['ssl_expiring'] }}</div>
            <p class="mt-1 text-xs text-zinc-500">Certificados &lt; {{ (int) config('monitor.degradation.ssl_warning_days', 15) }} días</p>
        </div>
    </div>

    <flux:card class="space-y-5 overflow-hidden px-4 sm:px-5">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading size="sm">Comparativo HTTPS en el tiempo</flux:heading>
                <flux:text size="sm">Izquierda: destinos Interior. Derecha: destinos Exterior. El eje X es el reloj: las líneas se desplazan a la izquierda en cada ciclo.</flux:text>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex flex-wrap rounded-lg border border-zinc-200 p-0.5 dark:border-white/10">
                    @foreach ($this->chartRangeOptions as $value => $label)
                        <flux:button size="sm" :variant="$chartRange === $value ? 'primary' : 'ghost'" wire:click="$set('chartRange', '{{ $value }}')">
                            {{ $label }}
                        </flux:button>
                    @endforeach
                </div>
                <div class="inline-flex rounded-lg border border-zinc-200 p-0.5 dark:border-white/10">
                    <flux:button size="sm" :variant="$chartMetric === 'total' ? 'primary' : 'ghost'" wire:click="$set('chartMetric', 'total')">
                        Tiempo total
                    </flux:button>
                    <flux:button size="sm" :variant="$chartMetric === 'net' ? 'primary' : 'ghost'" wire:click="$set('chartMetric', 'net')">
                        Solo red
                    </flux:button>
                </div>
                @if ($hiddenChartIds !== [])
                    <flux:button size="sm" variant="ghost" wire:click="showAllChartSites">Mostrar todos</flux:button>
                @endif
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-monitor.sites-line-chart
                :series="$http['site_series_internal'] ?? []"
                :metric="$chartMetric"
                :hidden="$hiddenChartIds"
                title="Sitios interiores"
                subtitle="Sondeados por la API Interior. Pasa el mouse o clic para fijar el recuadro."
                pin-key="internal"
                :show-controls="false"
                :window-start="$this->chartWindowStart()"
            />
            <x-monitor.sites-line-chart
                :series="$http['site_series_external'] ?? []"
                :metric="$chartMetric"
                :hidden="$hiddenChartIds"
                title="Sitios exteriores"
                subtitle="Sondeados por la API Exterior (VPS). Pasa el mouse o clic para fijar el recuadro."
                pin-key="external"
                :show-controls="false"
                :window-start="$this->chartWindowStart()"
            />
        </div>

        <div class="grid gap-3 border-t border-zinc-200 pt-4 sm:grid-cols-2 xl:grid-cols-4 dark:border-white/10">
            @foreach ($http['status_codes'] as $row)
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium">{{ $row['code'] }} · {{ $row['label'] }}</span>
                        <span class="font-mono text-xs text-zinc-500">{{ $row['count'] }} · {{ $row['pct'] }}%</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                        <div
                            @class([
                                'h-full rounded-full transition-all',
                                'bg-emerald-500' => $row['tone'] === 'emerald',
                                'bg-sky-500' => $row['tone'] === 'sky',
                                'bg-amber-500' => $row['tone'] === 'amber',
                                'bg-rose-500' => $row['tone'] === 'rose',
                            ])
                            style="width: {{ max($row['pct'], $row['count'] > 0 ? 4 : 0) }}%"
                        ></div>
                    </div>
                </div>
            @endforeach
        </div>
    </flux:card>

    @if ($http['services'] !== [])
        <flux:card class="space-y-4">
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
                <div>
                    <flux:heading size="sm">Web services en vivo</flux:heading>
                    <flux:text size="sm">Health JSON (MicroProfile / Spring) con cada dependencia al detalle.</flux:text>
                </div>
                <flux:badge color="lime">Intervalo por sitio</flux:badge>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($http['services'] as $service)
                    <a href="{{ route('monitor.sites.show', $service['id']) }}" wire:navigate class="block rounded-xl border border-zinc-200 p-4 transition hover:border-sky-400/50 dark:border-white/10">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span @class([
                                        'size-2.5 rounded-full',
                                        'bg-emerald-500 animate-pulse' => $service['ok'] === true,
                                        'bg-rose-500 animate-pulse' => $service['ok'] === false,
                                        'bg-zinc-400' => $service['ok'] === null,
                                    ])></span>
                                    <span class="font-medium">{{ $service['name'] }}</span>
                                    <flux:badge size="sm" :color="($service['probe_origin'] ?? 'internal') === 'external' ? 'sky' : 'zinc'">
                                        {{ $service['origin_label'] ?? 'Interior' }}
                                    </flux:badge>
                                </div>
                                <div class="mt-1 font-mono text-xs text-zinc-500">{{ $service['url'] }}</div>
                            </div>
                            <div class="text-right">
                                <span @class([
                                    'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-bold tracking-wide text-white shadow-sm',
                                    'bg-emerald-500 shadow-emerald-500/40' => $service['ok'] === true,
                                    'bg-rose-500 shadow-rose-500/40' => $service['ok'] === false,
                                    'bg-zinc-500' => $service['ok'] === null,
                                ])>
                                    {{ $service['status'] ?? 'PENDIENTE' }}
                                </span>
                                <div class="mt-1 text-[11px] text-zinc-500">
                                    Red {{ $service['network']['ms'] !== null ? $service['network']['ms'].' ms' : '—' }}
                                    · total {{ $service['ms'] ? $service['ms'].' ms' : '—' }}
                                    · {{ $service['checked_at'] ?? 'sin datos' }}
                                </div>
                            </div>
                        </div>

                        @if ($service['checks'] !== [])
                            <div class="mt-4 grid gap-2 sm:grid-cols-2">
                                @foreach ($service['checks'] as $check)
                                    <div class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-white/5">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="truncate text-sm font-medium">{{ $check['name'] }}</span>
                                            <flux:badge size="sm" :color="$check['up'] ? 'lime' : ($check['up'] === false ? 'rose' : 'zinc')">
                                                {{ $check['status'] }}
                                            </flux:badge>
                                        </div>
                                        @if (! empty($check['detail']))
                                            <div class="mt-1 truncate font-mono text-[11px] text-zinc-500">{{ $check['detail'] }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="mt-3 text-sm text-zinc-500">Aún no hay chequeos de health. El próximo ciclo los llenará.</p>
                        @endif
                    </a>
                @endforeach
            </div>
        </flux:card>
    @endif

    <flux:card class="space-y-4">
        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
            <div>
                <flux:heading size="sm">Sitios monitoreados</flux:heading>
                <flux:text size="sm">
                    El estado es el último sondeo. Sube o baja cada fila para armar tu orden. Ocultar solo esconde el destino en tu vista; el sondeo sigue.
                </flux:text>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($hasCustomTable)
                    <flux:button size="sm" variant="ghost" wire:click="resetTableLayout">Restablecer vista</flux:button>
                @endif
                <flux:badge :color="$http['success_pct'] >= 95 ? 'lime' : ($http['down'] > 0 ? 'rose' : 'amber')">
                    {{ $http['success_ok'] }}/{{ $http['success_total'] }} OK · {{ $http['success_pct'] }}%
                </flux:badge>
            </div>
        </div>

        @if ($table['hidden'] !== [])
            <div class="flex flex-wrap items-center gap-2 rounded-lg border border-dashed border-zinc-300 px-3 py-2 dark:border-white/15">
                <span class="text-xs text-zinc-500">Ocultos en tu vista:</span>
                @foreach ($table['hidden'] as $hiddenSite)
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-full border border-zinc-200 bg-white px-2 py-0.5 text-xs text-zinc-600 transition duration-150 hover:-translate-y-px hover:border-zinc-300 hover:bg-zinc-50 hover:shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-zinc-200 dark:hover:border-white/20 dark:hover:bg-white/10"
                        wire:click="showTableSite({{ (int) $hiddenSite['id'] }})"
                        title="Volver a mostrar {{ $hiddenSite['name'] }}"
                    >
                        {{ $hiddenSite['name'] }}
                        <span class="text-[10px] text-zinc-400">mostrar</span>
                    </button>
                @endforeach
            </div>
        @endif

        <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-950/50">
            <table class="w-full min-w-[72rem] table-fixed border-collapse text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-left text-xs font-medium tracking-wide text-zinc-500 uppercase dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-400">
                    <tr>
                        <th class="w-10 px-1 py-3 text-center">Vista</th>
                        <th class="w-[23%] px-4 py-3">Destino</th>
                        <th class="w-[20%] px-4 py-3">Estado ahora</th>
                        <th class="w-[15%] px-4 py-3">Fiabilidad</th>
                        <th class="w-[10%] px-4 py-3">Latencia de red</th>
                        <th class="w-[9%] px-4 py-3">Tiempo total</th>
                        <th class="w-[7%] px-4 py-3">SSL</th>
                        <th class="w-[6%] px-4 py-3">Headers</th>
                        <th class="w-[5%] px-4 py-3">Último</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                    @forelse ($table['visible'] as $site)
                        @php
                            $server = $site['web_server'];
                            $rel = $site['reliability'];
                            $net = $site['network'];
                            $grade = $site['security_grade'] ?? '—';
                        @endphp
                        <tr class="group align-top transition-colors duration-150 hover:bg-zinc-100 dark:hover:bg-zinc-800!" wire:key="dash-site-{{ $site['id'] }}">
                            <td class="w-10 px-1 py-3">
                                <div class="flex flex-col items-center gap-0.5 opacity-55 transition-opacity duration-150 group-hover:opacity-100">
                                    <button
                                        type="button"
                                        class="rounded-md p-0.5 text-emerald-600 transition duration-150 hover:scale-125 hover:bg-emerald-500/15 hover:shadow-sm hover:ring-1 hover:ring-emerald-500/40 disabled:cursor-not-allowed disabled:opacity-25 disabled:hover:scale-100 disabled:hover:bg-transparent disabled:hover:shadow-none disabled:hover:ring-0 dark:text-emerald-400"
                                        wire:click="moveTableSiteUp({{ (int) $site['id'] }})"
                                        @disabled($loop->first)
                                        title="Subir fila"
                                        aria-label="Subir fila"
                                    >
                                        <flux:icon.chevron-up class="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md p-0.5 text-orange-500 transition duration-150 hover:scale-125 hover:bg-orange-500/15 hover:shadow-sm hover:ring-1 hover:ring-orange-500/40 disabled:cursor-not-allowed disabled:opacity-25 disabled:hover:scale-100 disabled:hover:bg-transparent disabled:hover:shadow-none disabled:hover:ring-0 dark:text-orange-400"
                                        wire:click="moveTableSiteDown({{ (int) $site['id'] }})"
                                        @disabled($loop->last)
                                        title="Bajar fila"
                                        aria-label="Bajar fila"
                                    >
                                        <flux:icon.chevron-down class="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md p-0.5 text-red-600 transition duration-150 hover:scale-125 hover:bg-red-500/15 hover:shadow-sm hover:ring-1 hover:ring-red-500/40 dark:text-red-400"
                                        wire:click="hideTableSite({{ (int) $site['id'] }})"
                                        title="Ocultar de mi vista"
                                        aria-label="Ocultar de mi vista"
                                    >
                                        <flux:icon.eye-slash class="size-4" />
                                    </button>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <a href="{{ route('monitor.sites.show', $site['id']) }}" class="group flex min-w-0 items-start gap-3" wire:navigate>
                                    <span @class([
                                        'mt-1.5 size-2.5 shrink-0 rounded-full',
                                        'bg-emerald-500 shadow-[0_0_12px] shadow-emerald-500/70' => ($site['condition']['key'] ?? '') === 'ok',
                                        'bg-amber-500 shadow-[0_0_12px] shadow-amber-500/70' => in_array($site['condition']['key'] ?? '', ['degraded', 'unmonitored'], true),
                                        'bg-rose-500 shadow-[0_0_12px] shadow-rose-500/70' => ($site['condition']['key'] ?? '') === 'down',
                                        'bg-zinc-400' => ! in_array($site['condition']['key'] ?? '', ['ok', 'degraded', 'unmonitored', 'down'], true),
                                    ])></span>
                                    <span class="min-w-0">
                                        <span class="flex flex-wrap items-center gap-1.5">
                                            <span class="font-medium text-zinc-900 group-hover:underline dark:text-zinc-100">{{ $site['name'] }}</span>
                                            <flux:badge size="sm" :color="($site['probe_origin'] ?? 'internal') === 'external' ? 'sky' : 'zinc'">
                                                {{ $site['origin_label'] ?? 'Interior' }}
                                            </flux:badge>
                                            @if (($site['kind'] ?? 'http') === 'health')
                                                <flux:badge size="sm">WS</flux:badge>
                                            @endif
                                        </span>
                                        <span class="mt-0.5 block truncate font-mono text-xs text-zinc-500" title="{{ $site['url'] }}">{{ $site['url'] }}</span>
                                        <span class="mt-0.5 block truncate text-[11px] text-zinc-400" title="{{ $server['product'] }}">
                                            Servidor: {{ $server['family'] }}
                                            @if ($server['raw'] && $server['raw'] !== $server['family'])
                                                · {{ $server['raw'] }}
                                            @endif
                                        </span>
                                    </span>
                                </a>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <x-monitor.condition-badge :condition="$site['condition'] ?? []" />
                                    @if (($site['condition']['key'] ?? '') === 'ok' && $site['status'])
                                        <span class="text-[11px] text-zinc-500">{{ $site['status'] }}</span>
                                    @endif
                                </div>
                                <div class="mt-1 text-[11px] text-zinc-500">{{ $site['condition']['detail'] ?? $site['reason_label'] }}</div>
                                @if (! empty($site['diagnosis']['title']))
                                    <div class="mt-1 text-[11px] font-medium text-amber-700 dark:text-amber-300">{{ $site['diagnosis']['title'] }}</div>
                                @endif
                                @if ($site['reason_hint'])
                                    <div class="mt-1.5 rounded-md bg-zinc-50 px-2 py-1.5 text-[11px] leading-4 text-pretty break-words text-zinc-600 dark:bg-white/5 dark:text-zinc-300">
                                        {{ $site['reason_hint'] }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="space-y-1">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="font-mono text-sm font-semibold">{{ $rel['percent'] }}%</span>
                                        <flux:badge size="sm" :color="$rel['percent'] >= 95 ? 'lime' : ($rel['percent'] >= 70 ? 'amber' : 'rose')">
                                            {{ $rel['label'] }}
                                        </flux:badge>
                                    </div>
                                    <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                                        <div
                                            class="h-full rounded-full {{ $rel['percent'] >= 95 ? 'bg-emerald-500' : ($rel['percent'] >= 70 ? 'bg-amber-500' : 'bg-rose-500') }}"
                                            style="width: {{ $rel['percent'] }}%"
                                        ></div>
                                    </div>
                                    <div class="text-[11px] text-zinc-500">
                                        {{ $rel['ok_count'] }} de {{ $rel['sample_size'] }} OK
                                        @if ($rel['window'])
                                            · {{ $rel['window'] }}
                                        @endif
                                    </div>
                                    @if ($rel['hint'])
                                        <div class="text-[11px] leading-4 text-pretty break-words text-zinc-500">{{ $rel['hint'] }}</div>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-mono text-sm">{{ $net['ms'] !== null ? $net['ms'].' ms' : '—' }}</div>
                                <div class="text-[11px] text-zinc-500">{{ $net['label'] }}</div>
                                @if ($net['hint'])
                                    <div class="mt-1 text-[11px] leading-4 text-pretty break-words text-zinc-500">{{ $net['hint'] }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-mono text-sm">{{ $site['ms'] ? $site['ms'].' ms' : '—' }}</div>
                                <div class="text-[11px] text-zinc-500">Total · TTFB {{ $site['ttfb'] ? $site['ttfb'].' ms' : '—' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                @if ($site['ssl_days'] === null)
                                    <span class="text-zinc-400">Sin dato TLS</span>
                                @elseif ((int) $site['ssl_days'] < (int) config('monitor.degradation.ssl_warning_days', 15))
                                    <flux:badge color="amber">{{ $site['ssl_days'] }} d</flux:badge>
                                    <div class="mt-1 text-[11px] text-zinc-500">Certificado por vencer</div>
                                @else
                                    <span class="font-mono text-sm">{{ $site['ssl_days'] }} d</span>
                                    <div class="text-[11px] text-zinc-500">Días hasta vencimiento</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <flux:badge :color="in_array($grade, ['A', 'B'], true) ? 'lime' : (in_array($grade, ['C', 'D'], true) ? 'amber' : 'zinc')">
                                    Grado {{ $grade }}
                                </flux:badge>
                                <div class="mt-1 text-[11px] text-zinc-500">
                                    <a href="{{ route('documentation') }}#headers" class="underline decoration-zinc-400/70 underline-offset-2 hover:text-zinc-800 dark:hover:text-white" wire:navigate>Cabeceras de seguridad</a>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs text-zinc-500">{{ $site['checked_at'] ?? 'Nunca' }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8">
                                <div class="flex flex-col items-start gap-3">
                                    @if ($table['hidden'] !== [])
                                        <flux:heading size="sm">Todos los sitios están ocultos en tu vista</flux:heading>
                                        <flux:text>El monitoreo sigue. Vuelve a mostrar un destino o restablece la tabla.</flux:text>
                                        <flux:button size="sm" wire:click="resetTableLayout">Restablecer vista</flux:button>
                                    @else
                                        <flux:heading size="sm">Aún no hay sitios en el radar</flux:heading>
                                        <flux:text>Añade una URL HTTPS y lanza el primer chequeo para llenar este tablero.</flux:text>
                                        <flux:button href="{{ route('monitor.sites') }}" size="sm" wire:navigate>Crear primer sitio</flux:button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-dashed border-zinc-300 p-5 dark:border-white/15">
            <div class="flex items-center justify-between">
                <flux:heading size="sm">Logs Java</flux:heading>
                <flux:badge>Fase 2</flux:badge>
            </div>
            <p class="mt-2 text-sm leading-6 text-zinc-500">ERROR/WARN, excepciones frecuentes y tiempos de procesamiento desde Logback.</p>
        </div>
        <div class="rounded-xl border border-dashed border-zinc-300 p-5 dark:border-white/15">
            <div class="flex items-center justify-between">
                <flux:heading size="sm">Logs PHP</flux:heading>
                <flux:badge>Fase 2</flux:badge>
            </div>
            <p class="mt-2 text-sm leading-6 text-zinc-500">Errores por archivo, tendencias y memoria desde laravel.log / php-fpm.</p>
        </div>
        <div class="rounded-xl border border-dashed border-zinc-300 p-5 dark:border-white/15">
            <div class="flex items-center justify-between">
                <flux:heading size="sm">Sistema</flux:heading>
                <flux:badge>Fase 2</flux:badge>
            </div>
            <p class="mt-2 text-sm leading-6 text-zinc-500">CPU, memoria y salud de Redis/MySQL cuando el agente Python los publique.</p>
        </div>
    </div>
</section>
