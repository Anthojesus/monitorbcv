<?php

use App\Models\MonitorCheck;
use App\Models\MonitorCommand;
use App\Models\MonitorCommandRun;
use App\Models\MonitorTarget;
use App\Services\Monitoring\ChartRange;
use App\Services\Monitoring\CheckStats;
use App\Services\Monitoring\FastApiProbe;
use App\Services\Monitoring\MonitorCopy;
use App\Services\Monitoring\MonitorEngine;
use App\Services\Monitoring\MonitorSettings;
use App\Services\Monitoring\OriginCorrelation;
use App\Services\Monitoring\ProxyCorrelation;
use App\Services\Monitoring\SiteCondition;
use App\Services\Monitoring\SiteLineChart;
use App\Services\RemoteCommand\RemoteCommandException;
use App\Services\RemoteCommand\RemoteCommandRunner;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Detalle del sitio')] class extends Component
{
    use WithPagination;
    #[Locked]
    public int $targetId;

    public string $chartMetric = 'total';

    public string $chartRange = ChartRange::DEFAULT;

    public string $selectedCommandId = '';

    public string $changeConfirmation = '';

    public string $newCommandLabel = '';

    public string $newCommandText = '';

    public string $newCommandKind = 'query';

    public ?int $editingCommandId = null;

    public bool $showCommandForm = false;

    public bool $outputCleared = false;

    /**
     * @var list<int>
     */
    public array $hiddenChartIds = [];

    public function mount(MonitorTarget $target): void
    {
        $this->targetId = $target->id;
    }

    #[Computed]
    public function target(): MonitorTarget
    {
        return MonitorTarget::query()
            ->with(['server', 'commands', 'proxy', 'frontedSites'])
            ->findOrFail($this->targetId);
    }

    #[Computed]
    public function selectedCommand(): ?MonitorCommand
    {
        if ($this->selectedCommandId === '') {
            return null;
        }

        return $this->target->commands->firstWhere('id', (int) $this->selectedCommandId);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, MonitorCommandRun>
     */
    #[Computed]
    public function recentCommandRuns()
    {
        return MonitorCommandRun::query()
            ->with('user:id,name')
            ->where('monitor_target_id', $this->targetId)
            ->latest('started_at')
            ->limit(8)
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, MonitorCheck>
     */
    #[Computed]
    public function checks()
    {
        return MonitorCheck::query()
            ->where('monitor_target_id', $this->targetId)
            ->latest('checked_at')
            ->limit(40)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function reliability(): array
    {
        return MonitorCopy::reliability($this->checks, $this->target->last_ok);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function condition(): array
    {
        $runtime = app(FastApiProbe::class)->runtime();

        return app(SiteCondition::class)->evaluate(
            $this->target,
            $this->checks,
            (bool) data_get($runtime, $this->target->probeOrigin().'.api.ok'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function windowStats(): array
    {
        $points = app(SiteLineChart::class)->checksForChart($this->targetId, $this->chartSince());

        return CheckStats::fromChecks($points);
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function pairDiagnosis(): ?array
    {
        $pair = MonitorTarget::query()
            ->with(['checks' => fn ($query) => $query->latest('checked_at')->limit(8)])
            ->whereKeyNot($this->targetId)
            ->get()
            ->first(fn (MonitorTarget $other): bool => OriginCorrelation::normalizeUrl($other->url) === OriginCorrelation::normalizeUrl($this->target->url)
                && $other->probeOrigin() !== $this->target->probeOrigin());

        if ($pair === null) {
            return null;
        }

        $runtime = app(FastApiProbe::class)->runtime();
        $condition = app(SiteCondition::class);
        $thisCondition = $this->condition;
        $pairCondition = $condition->evaluate(
            $pair,
            $pair->checks,
            (bool) data_get($runtime, $pair->probeOrigin().'.api.ok'),
        );

        return app(OriginCorrelation::class)->forPair($this->target, $pair, $thisCondition, $pairCondition);
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function proxyDiagnosis(): ?array
    {
        $proxy = $this->target->proxy;

        if ($proxy === null) {
            return null;
        }

        $runtime = app(FastApiProbe::class)->runtime();
        $proxy->load(['checks' => fn ($query) => $query->latest('checked_at')->limit(8)]);
        $proxyCondition = app(SiteCondition::class)->evaluate(
            $proxy,
            $proxy->checks,
            (bool) data_get($runtime, $proxy->probeOrigin().'.api.ok'),
        );

        return app(ProxyCorrelation::class)->diagnose($this->target, $proxy, $this->condition, $proxyCondition);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function chartSeries(): array
    {
        return app(SiteLineChart::class)->series(collect([$this->target]), $this->chartSince());
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
        unset($this->chartSeries, $this->windowStats);
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, MonitorCheck>
     */
    public function history()
    {
        return MonitorCheck::query()
            ->where('monitor_target_id', $this->targetId)
            ->latest('checked_at')
            ->paginate(10);
    }

    public function tick(MonitorEngine $engine): void
    {
        $engine->runDueFromWeb();
        unset($this->target, $this->checks, $this->reliability, $this->chartSeries, $this->recentCommandRuns, $this->selectedCommand, $this->chartRangeOptions, $this->condition, $this->windowStats, $this->pairDiagnosis, $this->proxyDiagnosis);
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
        $this->hiddenChartIds = [];
    }

    public function showAllChartSites(): void
    {
        $this->hiddenChartIds = [];
    }

    public function probe(MonitorEngine $engine): void
    {
        $check = $engine->run($this->target);
        unset($this->target, $this->checks, $this->reliability, $this->chartSeries, $this->condition, $this->windowStats, $this->pairDiagnosis, $this->proxyDiagnosis);
        $this->resetPage();

        $unavailable = (bool) data_get($check->payload, 'probe_unavailable');

        $this->js('window.notify('.json_encode([
            'heading' => $unavailable ? 'Sonda no disponible' : ($check->ok ? 'Sitio operativo' : 'Sitio con incidencia'),
            'text' => $unavailable
                ? 'No se actualizó el UP/DOWN. '.$check->availability_reason
                : (($check->availability_reason ?? '').' · '.($check->total_ms ?? 0).' ms'),
            'variant' => $unavailable ? 'warning' : ($check->ok ? 'success' : 'danger'),
        ]).')');
    }

    public function execute(RemoteCommandRunner $runner): void
    {
        $this->validate([
            'selectedCommandId' => 'required|integer',
            'changeConfirmation' => 'nullable|string|max:120',
        ]);

        $user = Auth::user();

        if ($user === null || ! $user->canRunCommands()) {
            abort(403);
        }

        try {
            $run = $runner->run(
                $user,
                $this->target,
                (int) $this->selectedCommandId,
                $this->changeConfirmation,
            );
            $this->changeConfirmation = '';
            $this->outputCleared = false;
            unset($this->target, $this->recentCommandRuns, $this->selectedCommand);

            $this->js('window.notify('.json_encode([
                'heading' => $run->succeeded() ? 'Comando ejecutado' : 'Comando con error',
                'text' => $run->command_label.' · salida '.$run->statusLabel(),
                'variant' => $run->succeeded() ? 'success' : 'danger',
            ]).')');
        } catch (RemoteCommandException $exception) {
            unset($this->target, $this->recentCommandRuns, $this->selectedCommand);

            $this->js('window.notify('.json_encode([
                'heading' => 'No se ejecutó el comando',
                'text' => $exception->getMessage(),
                'variant' => 'danger',
            ]).')');
        }
    }

    public function clearCommandOutput(): void
    {
        abort_unless(Auth::user()?->canRunCommands(), 403);
        $this->outputCleared = true;
    }

    public function openCreateCommand(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);
        $this->editingCommandId = null;
        $this->newCommandLabel = '';
        $this->newCommandText = '';
        $this->newCommandKind = 'query';
        $this->resetValidation(['newCommandLabel', 'newCommandText', 'newCommandKind']);
        $this->showCommandForm = true;
    }

    public function startEditCommand(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);
        $command = $this->selectedCommand;

        if ($command === null || ! $command->isCustom()) {
            return;
        }

        $this->editingCommandId = $command->id;
        $this->newCommandLabel = $command->label;
        $this->newCommandText = $command->command;
        $this->newCommandKind = $command->kind;
        $this->resetValidation(['newCommandLabel', 'newCommandText', 'newCommandKind']);
        $this->showCommandForm = true;
    }

    public function cancelEditCommand(): void
    {
        $this->editingCommandId = null;
        $this->newCommandLabel = '';
        $this->newCommandText = '';
        $this->newCommandKind = 'query';
        $this->showCommandForm = false;
        $this->resetValidation(['newCommandLabel', 'newCommandText', 'newCommandKind']);
    }

    public function addCommand(RemoteCommandRunner $runner): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);
        $this->validate([
            'newCommandLabel' => 'required|string|max:120',
            'newCommandText' => 'required|string|max:255',
            'newCommandKind' => 'required|in:query,change',
        ]);

        try {
            $wasEditing = $this->editingCommandId !== null;
            $command = $wasEditing
                ? $runner->updateOnTarget(
                    $this->target,
                    (int) $this->editingCommandId,
                    $this->newCommandLabel,
                    $this->newCommandText,
                    $this->newCommandKind,
                )
                : $runner->addToTarget(
                    $this->target,
                    $this->newCommandLabel,
                    $this->newCommandText,
                    $this->newCommandKind,
                );
            $this->selectedCommandId = (string) $command->id;
            $this->editingCommandId = null;
            $this->newCommandLabel = '';
            $this->newCommandText = '';
            $this->newCommandKind = 'query';
            $this->showCommandForm = false;
            unset($this->target, $this->selectedCommand);

            $this->js('window.notify('.json_encode([
                'heading' => $wasEditing ? 'Comando actualizado' : 'Comando agregado a la lista',
                'text' => $command->label.' ya se puede seleccionar y ejecutar.',
                'variant' => 'success',
            ]).')');
        } catch (RemoteCommandException $exception) {
            $this->addError('newCommandText', $exception->getMessage());
        }
    }

    public function removeCommand(int $commandId, RemoteCommandRunner $runner): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);
        $runner->removeFromTarget($this->target, $commandId);

        if ((int) $this->selectedCommandId === $commandId) {
            $this->selectedCommandId = '';
            $this->changeConfirmation = '';
        }

        if ($this->editingCommandId === $commandId) {
            $this->cancelEditCommand();
        }

        unset($this->target, $this->selectedCommand);
        $this->js('window.notify({ heading: "Comando quitado de la lista", variant: "success" })');
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

<section class="flex w-full flex-col gap-6" wire:poll.5s="tick">
    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
        <div>
            <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('monitor.sites')" wire:navigate class="mb-2">
                Sitios
            </flux:button>
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="xl">{{ $this->target->name }}</flux:heading>
                <x-monitor.condition-badge :condition="$this->condition" />
                <flux:badge :color="$this->target->isExternalOrigin() ? 'sky' : 'zinc'">
                    {{ MonitorCopy::originLabel($this->target->probeOrigin()) }}
                </flux:badge>
                <flux:badge color="zinc">Cada {{ $this->target->interval_seconds }}s</flux:badge>
                @if ($this->target->isHealth())
                    <flux:badge color="sky">Web service</flux:badge>
                @endif
                @if ($this->target->isProxy())
                    <flux:badge color="amber">Proxy Linux</flux:badge>
                @elseif ($this->target->proxy)
                    <a href="{{ route('monitor.sites.show', $this->target->proxy) }}" wire:navigate>
                        <flux:badge color="amber">Proxy {{ $this->target->proxy->name }}</flux:badge>
                    </a>
                @endif
            </div>
            <flux:text class="font-mono">{{ $this->target->url }}</flux:text>
            @if (($this->condition['key'] ?? '') === 'degraded')
                <flux:text size="sm">{{ $this->condition['detail'] }}</flux:text>
            @endif
        </div>
        <flux:button icon="arrow-path" wire:click="probe" wire:loading.attr="disabled">Probar ahora</flux:button>
    </div>

    @if ($this->pairDiagnosis)
        <flux:callout icon="exclamation-triangle" :variant="($this->pairDiagnosis['tone'] ?? '') === 'rose' ? 'danger' : 'warning'">
            <span class="font-medium">{{ $this->pairDiagnosis['title'] }}.</span>
            {{ $this->pairDiagnosis['detail'] }}
        </flux:callout>
    @endif

    @if ($this->proxyDiagnosis)
        <flux:callout icon="exclamation-triangle" :variant="($this->proxyDiagnosis['tone'] ?? '') === 'rose' ? 'danger' : 'warning'">
            <span class="font-medium">{{ $this->proxyDiagnosis['title'] }}.</span>
            {{ $this->proxyDiagnosis['detail'] }}
            <a class="ml-1 underline" href="{{ route('monitor.sites.show', $this->target->proxy_target_id) }}" wire:navigate>Ver proxy</a>
        </flux:callout>
    @endif

    @if ($this->target->isProxy() && $this->target->frontedSites->isNotEmpty())
        <flux:card class="space-y-2">
            <flux:heading size="sm">Aplicaciones detrás de este proxy</flux:heading>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->target->frontedSites as $fronted)
                    <a href="{{ route('monitor.sites.show', $fronted) }}" wire:navigate>
                        <flux:badge :color="$fronted->last_ok === false ? 'rose' : 'zinc'">{{ $fronted->name }}</flux:badge>
                    </a>
                @endforeach
            </div>
        </flux:card>
    @endif

    @php
        $server = $this->target->server;
        $selected = $this->selectedCommand;
        $latestRun = $this->recentCommandRuns->first();
    @endphp
    <div class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <flux:heading size="lg">Acciones de servidor</flux:heading>
                <flux:text size="sm">
                    @if (auth()->user()?->isAdmin())
                        Elija un comando y ejecútelo. Para crear o editar un alias use “Nuevo comando”.
                    @elseif (auth()->user()?->canRunCommands())
                        Elija un comando asignado a este destino y ejecútelo. Un administrador habilita esta acción por usuario.
                    @else
                        Puede ver el estado del sitio. La ejecución de comandos la asigna un administrador.
                    @endif
                    La clave SSH está cifrada y no se muestra.
                </flux:text>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($server?->isReady())
                    <flux:badge color="zinc">{{ $server->username.'@'.$server->host.':'.$server->port }}</flux:badge>
                @endif
                @if (auth()->user()?->isAdmin())
                    <flux:button icon="plus" wire:click="openCreateCommand">Nuevo comando</flux:button>
                @endif
            </div>
        </div>

        @if (! $server?->isReady())
            <flux:callout icon="information-circle">
                Configure host, usuario y clave SSH al editar este destino. Puede ir armando la lista de comandos; la ejecución se habilita cuando existan credenciales.
            </flux:callout>
        @endif

        <flux:card class="space-y-5">
            <div class="space-y-3">
                <flux:heading size="sm">Elegir y ejecutar</flux:heading>
                @if ($this->target->commands->isEmpty())
                    <flux:text size="sm">
                        Todavía no hay comandos.
                        @if (auth()->user()?->isAdmin())
                            Pulse “Nuevo comando” para agregar un alias o un comando propio.
                        @endif
                    </flux:text>
                @elseif (! auth()->user()?->canRunCommands())
                    <flux:callout icon="information-circle">
                        Este usuario no tiene ejecución de comandos. Un administrador puede habilitarla en Ajustes → Usuarios.
                    </flux:callout>
                @else
                    <div class="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end">
                        <flux:select wire:model.live="selectedCommandId" label="Comando de la lista">
                            <flux:select.option value="">Seleccione un comando…</flux:select.option>
                            @foreach ($this->target->commands as $command)
                                <flux:select.option value="{{ $command->id }}">
                                    {{ $command->isChange() ? 'Cambio' : 'Consulta' }} · {{ $command->label }}{{ $command->isCustom() ? ' (alias)' : '' }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:button
                                wire:click="execute"
                                wire:loading.attr="disabled"
                                :variant="$selected?->isChange() ? 'danger' : 'primary'"
                                :disabled="! $server?->isReady() || $selected === null || ($selected->isChange() && $changeConfirmation !== $this->target->name)"
                            >
                                {{ $selected?->isChange() ? 'Ejecutar cambio' : 'Ejecutar consulta' }}
                            </flux:button>
                            @if ($latestRun && ! $this->outputCleared)
                                <flux:button
                                    variant="ghost"
                                    icon="arrow-path"
                                    wire:click="clearCommandOutput"
                                    wire:loading.attr="disabled"
                                >
                                    Reiniciar salida
                                </flux:button>
                            @endif
                        </div>
                    </div>
                @endif

                @if ($selected)
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:text class="font-mono text-xs">{{ $selected->command }}</flux:text>
                        @if ($selected->isCustom() && auth()->user()?->isAdmin())
                            <flux:button size="sm" variant="ghost" wire:click="startEditCommand">Editar</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="removeCommand({{ $selected->id }})" wire:confirm="¿Quitar este alias de la lista?">
                                Quitar de la lista
                            </flux:button>
                        @endif
                    </div>
                @endif

                @if ($selected?->isChange())
                    <flux:callout icon="exclamation-triangle" variant="warning">
                        Este comando modifica el servidor de producción. Escriba el nombre exacto del sitio para confirmar.
                    </flux:callout>
                    <flux:input
                        wire:model.live="changeConfirmation"
                        label="Confirmación"
                        :placeholder="$this->target->name"
                        autocomplete="off"
                    />
                @endif
            </div>

            @if ($latestRun && $this->outputCleared)
                <flux:callout icon="information-circle">
                    Salida limpia. Ejecute el siguiente comando para validar el resultado.
                </flux:callout>
            @elseif ($latestRun)
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="sm">Última salida</flux:heading>
                        <flux:badge :color="$latestRun->succeeded() ? 'lime' : 'rose'">{{ $latestRun->statusLabel() }}</flux:badge>
                        <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="clearCommandOutput">
                            Reiniciar
                        </flux:button>
                        <flux:text size="sm">
                            {{ $latestRun->command_label }}
                            · {{ $latestRun->user?->name ?? 'usuario' }}
                            · {{ $latestRun->started_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s') }}
                            @if ($latestRun->exit_code !== null)
                                · exit {{ $latestRun->exit_code }}
                            @endif
                        </flux:text>
                    </div>
                    @if ($latestRun->error_message)
                        <flux:text size="sm">{{ $latestRun->error_message }}</flux:text>
                    @endif
                    <pre class="max-h-[32rem] overflow-auto whitespace-pre-wrap break-all rounded-lg bg-zinc-950 p-4 text-xs leading-relaxed text-zinc-100">{{ $latestRun->displayOutput() }}</pre>
                </div>
            @endif
        </flux:card>

        <flux:modal wire:model="showCommandForm" class="md:w-2xl">
            <form wire:submit="addCommand" class="space-y-4">
                <div>
                    <flux:heading size="lg">
                        {{ $editingCommandId ? 'Editar comando' : 'Nuevo comando o alias' }}
                    </flux:heading>
                    <flux:text class="mt-1">
                        @if ($editingCommandId)
                            Cambie el alias o anteponga sudo si el archivo exige privilegios de root. Ejemplo:
                            <span class="font-mono">sudo tail -n 50 /var/log/nginx/entregaguardia-access.log</span>
                        @else
                            Si el servidor tiene un alias propio, escríbalo tal cual. Para logs de Nginx use sudo. Queda solo en este destino.
                        @endif
                    </flux:text>
                </div>
                <flux:input wire:model="newCommandLabel" label="Nombre para reconocerlo" placeholder="Últimas 50 líneas access log" />
                <flux:input wire:model="newCommandText" label="Comando o alias" placeholder="sudo tail -n 50 /var/log/nginx/access.log" class="font-mono" />
                <flux:select wire:model="newCommandKind" label="¿Qué hace?">
                    <flux:select.option value="query">Consulta: solo mira el estado</flux:select.option>
                    <flux:select.option value="change">Cambio: reinicia o modifica</flux:select.option>
                </flux:select>
                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="ghost" wire:click="cancelEditCommand">Cancelar</flux:button>
                    <flux:button type="submit" variant="primary">
                        {{ $editingCommandId ? 'Guardar cambios' : 'Agregar a la lista' }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>

        @if ($this->recentCommandRuns->isNotEmpty())
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Cuando</flux:table.column>
                    <flux:table.column>Comando</flux:table.column>
                    <flux:table.column>Tipo</flux:table.column>
                    <flux:table.column>Quién</flux:table.column>
                    <flux:table.column>Resultado</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->recentCommandRuns as $run)
                        <flux:table.row>
                            <flux:table.cell>{{ $run->started_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s') }}</flux:table.cell>
                            <flux:table.cell>{{ $run->command_label }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$run->kind === 'change' ? 'amber' : 'zinc'">{{ $run->kind === 'change' ? 'Cambio' : 'Consulta' }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ $run->user?->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$run->succeeded() ? 'lime' : 'rose'">{{ $run->statusLabel() }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    @php
        $latest = $this->checks->first();
        $probeUnavailable = $latest !== null && (
            (bool) data_get($latest->payload, 'probe_unavailable')
            || $latest->availability_reason === 'probe_unavailable'
        );
        $displayOk = $probeUnavailable ? $this->target->last_ok : $latest?->ok;
    @endphp
    @if ($latest)
        @if ($probeUnavailable)
            <flux:callout icon="exclamation-triangle" variant="warning">
                No se está monitoreando: el servicio {{ MonitorCopy::originLabel($this->target->probeOrigin()) }} está caído o apagado. No es una falla del portal. El último UP/DOWN se conserva.
            </flux:callout>
        @endif
        @if ($latest->availability_reason === 'http_reachable' && $latest->status_code !== null && $latest->status_code >= 400)
            <flux:callout icon="exclamation-triangle" variant="warning">
                {{ MonitorCopy::reasonHint($latest->availability_reason, $latest->status_code, $this->target->probeOrigin()) }}
            </flux:callout>
        @endif
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
            <flux:card class="space-y-3">
                <flux:text size="sm">Estado ahora</flux:text>
                <span @class([
                    'inline-flex items-center rounded-lg px-3 py-1.5 text-2xl font-bold tracking-wide',
                    'bg-amber-500 text-white shadow-lg shadow-amber-500/30' => $probeUnavailable,
                    'bg-emerald-500 text-white shadow-lg shadow-emerald-500/30' => ! $probeUnavailable && $displayOk,
                    'bg-rose-500 text-white shadow-lg shadow-rose-500/30' => ! $probeUnavailable && ! $displayOk,
                ])>
                    @if ($probeUnavailable)
                        No monitoreado
                    @else
                        {{ $displayOk ? 'UP' : 'DOWN' }}{{ $displayOk && $latest->status_code ? ' '.$latest->status_code : '' }}
                    @endif
                </span>
                <flux:text size="sm">{{ MonitorCopy::reason($latest->availability_reason) }}</flux:text>
                @if ($hint = MonitorCopy::reasonHint($latest->availability_reason, $latest->status_code, $this->target->probeOrigin()))
                    @unless ($latest->availability_reason === 'http_reachable' && $latest->status_code !== null && $latest->status_code >= 400)
                        <flux:text size="sm">{{ $hint }}</flux:text>
                    @endunless
                @endif
                @if ($message = data_get($latest->payload, 'error.message'))
                    <flux:text size="sm" class="break-all">{{ $message }}</flux:text>
                @endif
            </flux:card>
            <flux:card class="space-y-1">
                <flux:text size="sm">Fiabilidad</flux:text>
                <flux:heading>{{ $this->reliability['percent'] }}%</flux:heading>
                <flux:text size="sm">{{ $this->reliability['ok_count'] }} de {{ $this->reliability['sample_size'] }} OK · {{ $this->reliability['label'] }}</flux:text>
                <flux:text size="sm">{{ $this->reliability['hint'] }}</flux:text>
            </flux:card>
            @php $network = MonitorCopy::network($latest->payload); @endphp
            <flux:card class="space-y-1">
                <flux:text size="sm">Latencia de red</flux:text>
                <flux:heading>{{ $network['ms'] !== null ? $network['ms'].' ms' : '—' }}</flux:heading>
                <flux:text size="sm">{{ $network['label'] }}</flux:text>
                <flux:text size="sm">{{ $network['hint'] }}</flux:text>
            </flux:card>
            <flux:card class="space-y-1">
                <flux:text size="sm">Tiempo total</flux:text>
                <flux:heading>{{ $latest->total_ms }} ms</flux:heading>
                <flux:text size="sm">TTFB {{ data_get($latest->payload, 'timings_ms.ttfb') }} ms · incluye app</flux:text>
            </flux:card>
            <flux:card class="space-y-1">
                <flux:text size="sm">Certificado</flux:text>
                <flux:heading>{{ data_get($latest->payload, 'tls.certificate.days_remaining') ?? '—' }} d</flux:heading>
                <flux:text size="sm">{{ MonitorCopy::text(data_get($latest->payload, 'tls.certificate.issuer')) }}</flux:text>
            </flux:card>
            <flux:card class="space-y-1">
                <flux:text size="sm">
                    <a href="{{ route('documentation') }}#headers" class="underline decoration-zinc-400/70 underline-offset-2" wire:navigate>Headers de seguridad</a>
                </flux:text>
                <flux:heading>Grado {{ data_get($latest->payload, 'security.grade') ?? '—' }}</flux:heading>
                <flux:text size="sm">Score {{ data_get($latest->payload, 'security.score') }} / 100</flux:text>
            </flux:card>
            @php $server = MonitorCopy::webServer($latest->payload); @endphp
            <flux:card class="space-y-1">
                <flux:text size="sm">Servidor web</flux:text>
                <flux:heading>{{ $server['family'] }}</flux:heading>
                <flux:text size="sm">{{ $server['product'] }}</flux:text>
            </flux:card>
        </div>

        @php $health = data_get($latest->payload, 'health'); @endphp
        @if (is_array($health))
            <div class="space-y-3">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <flux:heading size="lg">Dependencias del servicio</flux:heading>
                        <flux:text size="sm">
                            {{ $health['up_count'] ?? 0 }} UP
                            · {{ $health['down_count'] ?? 0 }} DOWN
                            · formato {{ $health['format'] ?? 'json' }}
                            · se actualiza cada {{ $this->target->interval_seconds }}s
                        </flux:text>
                    </div>
                    <flux:badge :color="($health['up'] ?? false) ? 'lime' : 'rose'">{{ $health['status'] ?? 'UNKNOWN' }}</flux:badge>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    @forelse (($health['checks'] ?? []) as $item)
                        <flux:card class="space-y-2">
                            <div class="flex items-start justify-between gap-3">
                                <flux:heading size="sm">{{ $item['name'] }}</flux:heading>
                                <flux:badge :color="($item['up'] ?? null) === true ? 'lime' : (($item['up'] ?? null) === false ? 'rose' : 'zinc')">
                                    {{ $item['status'] }}
                                </flux:badge>
                            </div>
                            @if (! empty($item['detail']))
                                <flux:text class="font-mono text-xs">{{ $item['detail'] }}</flux:text>
                            @endif
                            @if (! empty($item['data']) && is_array($item['data']))
                                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                                    @foreach ($item['data'] as $key => $value)
                                        <dt class="text-zinc-500">{{ $key }}</dt>
                                        <dd class="font-mono">{{ MonitorCopy::text($value) }}</dd>
                                    @endforeach
                                </dl>
                            @endif
                        </flux:card>
                    @empty
                        <flux:callout icon="information-circle">El JSON no trajo checks individuales; solo el status global {{ $health['status'] ?? '' }}.</flux:callout>
                    @endforelse
                </div>
            </div>
        @endif

        <flux:card class="space-y-4 overflow-hidden px-4 sm:px-5">
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-white/10">
                    <div class="text-xs text-zinc-500">Tiempo total en esta ventana</div>
                    <div class="mt-1 font-mono">
                        p95 {{ $this->windowStats['p95_total'] !== null ? $this->windowStats['p95_total'].' ms' : '—' }}
                        · p99 {{ $this->windowStats['p99_total'] !== null ? $this->windowStats['p99_total'].' ms' : '—' }}
                    </div>
                </div>
                <div class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-white/10">
                    <div class="text-xs text-zinc-500">TTFB en esta ventana</div>
                    <div class="mt-1 font-mono">
                        p95 {{ $this->windowStats['p95_ttfb'] !== null ? $this->windowStats['p95_ttfb'].' ms' : '—' }}
                        · p99 {{ $this->windowStats['p99_ttfb'] !== null ? $this->windowStats['p99_ttfb'].' ms' : '—' }}
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex flex-wrap rounded-lg border border-zinc-200 p-0.5 dark:border-white/10">
                    @foreach ($this->chartRangeOptions as $value => $label)
                        <flux:button size="sm" :variant="$chartRange === $value ? 'primary' : 'ghost'" wire:click="$set('chartRange', '{{ $value }}')">
                            {{ $label }}
                        </flux:button>
                    @endforeach
                </div>
            </div>
            <x-monitor.sites-line-chart
                :series="$this->chartSeries"
                :metric="$chartMetric"
                :hidden="$hiddenChartIds"
                title="Evolución de este sitio"
                subtitle="El eje X es el reloj: la línea se desplaza a la izquierda en cada ciclo."
                :window-start="$this->chartWindowStart()"
            />
        </flux:card>

        @if (data_get($latest->payload, 'tls.trust') === 'issuer_untrusted')
            <flux:callout icon="shield-check" variant="warning">
                El sitio respondió. La CA corporativa no está en el almacén público; el chequeo se aceptó para evitar un falso DOWN. Un certificado vencido o con hostname incorrecto seguiría marcándose como incidencia.
            </flux:callout>
        @endif

        <flux:accordion>
            <flux:accordion.item expanded>
                <flux:accordion.heading>JSON del último chequeo</flux:accordion.heading>
                <flux:accordion.content>
                    <pre class="max-h-[32rem] overflow-auto rounded-lg bg-zinc-950 p-4 text-xs leading-relaxed text-zinc-100">{{ json_encode($latest->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                </flux:accordion.content>
            </flux:accordion.item>
        </flux:accordion>
    @else
        <flux:callout icon="information-circle">
            Todavía no hay chequeos. Pulsa “Probar ahora” para generar el primer JSON.
        </flux:callout>
    @endif

    <div>
        <flux:heading size="lg">Historial</flux:heading>
        <flux:text size="sm">
            Cada fila es un sondeo. Se muestran 10 por página, empezando por los más recientes.
            El motivo explica por qué se marcó OK o FAIL.
        </flux:text>
    </div>
    @php $history = $this->history(); @endphp
    <flux:table :paginate="$history">
        <flux:table.columns>
            <flux:table.column>Cuando</flux:table.column>
            <flux:table.column>Resultado</flux:table.column>
            <flux:table.column>HTTP</flux:table.column>
            <flux:table.column>ms</flux:table.column>
            <flux:table.column>Motivo</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($history as $check)
                <flux:table.row>
                    <flux:table.cell>{{ $check->checked_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge :color="data_get($check->payload, 'probe_unavailable') ? 'amber' : ($check->ok ? 'lime' : 'rose')">
                            {{ data_get($check->payload, 'probe_unavailable') ? 'S/MON' : ($check->ok ? 'OK' : 'FAIL') }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="font-mono">{{ $check->status_code ?? '—' }}</flux:table.cell>
                    <flux:table.cell class="font-mono">{{ $check->total_ms ?? '—' }}</flux:table.cell>
                    <flux:table.cell>
                        <div>{{ MonitorCopy::reason($check->availability_reason) }}</div>
                        @if ($hint = MonitorCopy::reasonHint($check->availability_reason, $check->status_code, $this->target->probeOrigin()))
                            <div class="mt-0.5 max-w-md text-[11px] leading-4 text-zinc-500">{{ $hint }}</div>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5">Aún no hay sondeos en el historial.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
