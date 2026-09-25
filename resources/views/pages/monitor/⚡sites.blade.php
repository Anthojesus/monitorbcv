<?php

use App\Models\MonitorCommand;
use App\Models\MonitorTarget;
use App\Services\Monitoring\MonitorCopy;
use App\Services\Monitoring\MonitorEngine;
use App\Services\Monitoring\MonitorSettings;
use App\Services\Monitoring\SiteCondition;
use App\Services\RemoteCommand\RemoteCommandRunner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Sitios')] class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    #[Validate('required|string|max:120')]
    public string $name = '';

    #[Validate('required|url|max:2048')]
    public string $url = '';

    #[Validate('required|in:GET,HEAD,POST')]
    public string $method = 'GET';

    #[Validate('nullable|string|max:120')]
    public ?string $expected_keyword = null;

    #[Validate('required|integer|min:5|max:60')]
    public int $timeout_seconds = 15;

    public int $interval_seconds = 5;

    public bool $verify_ssl = true;

    #[Validate('required|in:http,health,proxy')]
    public string $kind = '';

    #[Validate('nullable|integer')]
    public ?int $proxy_target_id = null;

    #[Validate('required|in:internal,external')]
    public string $probe_origin = 'internal';

    #[Validate('required|in:strict,reachable')]
    public string $availability_mode = 'strict';

    #[Validate('required|string|max:120')]
    public string $expected_status_input = '200, 201, 204, 301, 302, 303, 307, 308';

    public string $ssh_host = '';

    public int $ssh_port = 22;

    public string $ssh_username = '';

    public string $ssh_password = '';

    public bool $hasStoredPassword = false;

    /**
     * @var list<int|string>
     */
    public array $commandIds = [];

    /**
     * @var list<array{label: string, command: string, kind: string}>
     */
    public array $pendingCustomCommands = [];

    public string $newCommandLabel = '';

    public string $newCommandText = '';

    public string $newCommandKind = 'query';

    /**
     * @return Collection<int, MonitorTarget>
     */
    #[Computed]
    public function sites(): Collection
    {
        return MonitorTarget::query()
            ->with([
                'server',
                'proxy',
                'checks' => fn ($query) => $query->latest('checked_at')->limit(8),
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, MonitorTarget>
     */
    #[Computed]
    public function proxyOptions(): Collection
    {
        return MonitorTarget::query()
            ->where('kind', MonitorTarget::KIND_PROXY)
            ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
            ->orderBy('name')
            ->get(['id', 'name', 'url']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function siteConditions(): array
    {
        $condition = app(SiteCondition::class);
        $rows = [];

        foreach ($this->sites as $site) {
            $rows[$site->id] = $condition->evaluate($site, $site->checks);
        }

        return $rows;
    }

    public function updatedUrl(): void
    {
        $url = strtolower($this->url);

        if ($this->kind !== MonitorTarget::KIND_PROXY && (str_contains($url, '/health') || str_contains($url, '/actuator'))) {
            $this->kind = 'health';
        }

        if ($this->ssh_host === '') {
            $host = parse_url($this->url, PHP_URL_HOST);
            $this->ssh_host = is_string($host) ? $host : '';
        }

        $guessed = MonitorTarget::guessOrigin($this->url);

        if ($this->editingId === null || $guessed === MonitorTarget::ORIGIN_INTERNAL) {
            $this->probe_origin = $guessed;
        }
    }

    /**
     * @return list<string>
     */
    public static function proxyCommandSlugs(): array
    {
        return [
            'hostname', 'uptime', 'df', 'free',
            'nginx-active', 'nginx-status', 'nginx-logs', 'nginx-test', 'ss-http',
            'nginx-reload', 'nginx-restart',
            'haproxy-active', 'haproxy-status', 'haproxy-logs',
            'haproxy-reload', 'haproxy-restart',
        ];
    }

    /**
     * @return Collection<int, MonitorCommand>
     */
    #[Computed]
    public function formCommands(): Collection
    {
        $catalog = RemoteCommandRunner::catalog($this->editingId);

        if ($this->kind !== MonitorTarget::KIND_PROXY) {
            return $catalog;
        }

        return $catalog
            ->filter(fn (MonitorCommand $command): bool => $command->isCustom() || in_array($command->slug, self::proxyCommandSlugs(), true))
            ->values();
    }

    public function updatedKind(): void
    {
        if ($this->kind === MonitorTarget::KIND_PROXY) {
            $this->proxy_target_id = null;
            $this->availability_mode = 'reachable';
            $this->method = 'GET';
            $this->expected_keyword = null;

            if ($this->editingId === null) {
                $this->commandIds = MonitorCommand::query()
                    ->enabled()
                    ->where('is_custom', false)
                    ->whereIn('slug', self::proxyCommandSlugs())
                    ->pluck('id')
                    ->all();
            }

            unset($this->formCommands);

            return;
        }

        if ($this->kind === 'health' || $this->editingId === null) {
            $this->availability_mode = 'strict';
        }

        $this->method = 'GET';

        if ($this->editingId === null) {
            $this->commandIds = MonitorCommand::query()
                ->enabled()
                ->where('is_custom', false)
                ->where('kind', 'query')
                ->pluck('id')
                ->all();
        }

        unset($this->formCommands);
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function intervalOptions(): array
    {
        $options = app(MonitorSettings::class)->intervalSeconds();

        if (! in_array($this->interval_seconds, $options, true)) {
            $options[] = $this->interval_seconds;
            sort($options);
        }

        return $options;
    }

    public function create(): void
    {
        $this->authorizeManage();
        $this->editingId = null;
        $this->reset('name', 'url', 'expected_keyword', 'probe_origin', 'proxy_target_id', 'ssh_host', 'ssh_username', 'ssh_password', 'newCommandLabel', 'newCommandText', 'pendingCustomCommands');
        $this->method = 'GET';
        $this->timeout_seconds = 15;
        $this->interval_seconds = $this->defaultInterval();
        $this->verify_ssl = true;
        $this->kind = '';
        $this->proxy_target_id = null;
        $this->probe_origin = MonitorTarget::ORIGIN_INTERNAL;
        $this->availability_mode = 'strict';
        $this->expected_status_input = implode(', ', MonitorTarget::defaultExpectedStatus());
        $this->ssh_port = 22;
        $this->hasStoredPassword = false;
        $this->newCommandKind = 'query';
        $this->commandIds = MonitorCommand::query()
            ->enabled()
            ->where('is_custom', false)
            ->where('kind', 'query')
            ->pluck('id')
            ->all();
        $this->showForm = true;
        unset($this->formCommands, $this->proxyOptions);
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();
        $target = MonitorTarget::query()->with(['server', 'commands'])->findOrFail($id);
        $this->editingId = $target->id;
        $this->name = $target->name;
        $this->url = $target->url;
        $this->method = $target->method;
        $this->expected_keyword = $target->expected_keyword;
        $this->availability_mode = $target->acceptsAnyHttpStatus() ? 'reachable' : 'strict';
        $this->expected_status_input = implode(', ', $target->acceptsAnyHttpStatus()
            ? MonitorTarget::defaultExpectedStatus()
            : $target->expectedStatusCodes());
        $this->timeout_seconds = $target->timeout_seconds;
        $this->interval_seconds = max(1, (int) $target->interval_seconds);
        $this->verify_ssl = $target->verify_ssl;
        $this->kind = $target->kind;
        $this->proxy_target_id = $target->isProxy() ? null : $target->proxy_target_id;
        $this->probe_origin = $target->probeOrigin();
        $this->ssh_host = $target->server?->host ?? '';
        $this->ssh_port = $target->server?->port ?? 22;
        $this->ssh_username = $target->server?->username ?? '';
        $this->ssh_password = '';
        $this->hasStoredPassword = $target->server?->hasPassword() ?? false;
        $this->commandIds = $target->commands->pluck('id')->all();
        $this->pendingCustomCommands = [];
        $this->newCommandLabel = '';
        $this->newCommandText = '';
        $this->newCommandKind = 'query';
        $this->showForm = true;
        unset($this->formCommands, $this->proxyOptions);
    }

    public function addCustomCommandToForm(): void
    {
        $this->authorizeManage();
        $this->validate([
            'newCommandLabel' => 'required|string|max:120',
            'newCommandText' => 'required|string|max:255',
            'newCommandKind' => 'required|in:query,change',
        ]);

        $command = trim($this->newCommandText);

        if (! RemoteCommandRunner::commandIsSafe($command)) {
            $this->addError('newCommandText', 'Use solo letras, números, espacios y - _ / . : =. Sin ; | & $ ` ni paréntesis.');

            return;
        }

        $alreadyPending = collect($this->pendingCustomCommands)->contains(
            fn (array $draft): bool => $draft['command'] === $command,
        );

        if ($alreadyPending) {
            $this->addError('newCommandText', 'Ese comando ya está en la lista pendiente.');

            return;
        }

        $this->pendingCustomCommands[] = [
            'label' => trim($this->newCommandLabel),
            'command' => $command,
            'kind' => $this->newCommandKind,
        ];
        $this->newCommandLabel = '';
        $this->newCommandText = '';
        $this->newCommandKind = 'query';
        $this->resetValidation(['newCommandLabel', 'newCommandText', 'newCommandKind']);
    }

    public function removePendingCustomCommand(int $index): void
    {
        unset($this->pendingCustomCommands[$index]);
        $this->pendingCustomCommands = array_values($this->pendingCustomCommands);
    }

    public function save(): void
    {
        $this->authorizeManage();
        if (! $this->proxy_target_id) {
            $this->proxy_target_id = null;
        }
        $this->validate();
        $this->validate([
            'interval_seconds' => ['required', 'integer', Rule::in($this->intervalOptions)],
            'ssh_host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'ssh_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'ssh_username' => ['nullable', 'required_with:ssh_host', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'ssh_password' => [
                Rule::requiredIf(fn (): bool => $this->ssh_host !== '' && ! $this->hasStoredPassword),
                'nullable',
                'string',
                'max:255',
            ],
            'commandIds' => ['array'],
            'commandIds.*' => ['integer', 'exists:monitor_commands,id'],
            'proxy_target_id' => [
                'nullable',
                'integer',
                Rule::exists('monitor_targets', 'id')->where('kind', MonitorTarget::KIND_PROXY),
                Rule::notIn([$this->editingId]),
            ],
        ]);

        $attributes = [
            'name' => $this->name,
            'url' => $this->url,
            'kind' => $this->kind,
            'probe_origin' => $this->probe_origin,
            'proxy_target_id' => $this->kind === MonitorTarget::KIND_PROXY ? null : $this->proxy_target_id,
            'method' => $this->method,
            'expected_keyword' => $this->expected_keyword,
            'timeout_seconds' => $this->timeout_seconds,
            'interval_seconds' => $this->interval_seconds,
            'expected_status' => $this->resolvedExpectedStatus(),
            'is_enabled' => true,
            'verify_ssl' => $this->verify_ssl,
        ];

        $target = $this->editingId !== null
            ? tap(MonitorTarget::query()->findOrFail($this->editingId))->update($attributes)
            : MonitorTarget::query()->create($attributes);

        $this->syncServerAndCommands($target);
        $this->ssh_password = '';
        unset($this->sites, $this->siteConditions, $this->proxyOptions);
        $this->closeForm();
        $this->js('window.notify({ heading: "Sitio guardado", text: "Ya puedes lanzar un chequeo.", variant: "success" })');
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->kind = '';
        $this->editingId = null;
        $this->pendingCustomCommands = [];
        unset($this->formCommands, $this->proxyOptions);
    }

    /**
     * @return list<int>
     */
    private function resolvedExpectedStatus(): array
    {
        if ($this->kind === 'health') {
            return [200];
        }

        if ($this->kind === MonitorTarget::KIND_PROXY && $this->availability_mode === 'reachable') {
            return [MonitorTarget::ANY_HTTP_STATUS];
        }

        if ($this->availability_mode === 'reachable') {
            return [MonitorTarget::ANY_HTTP_STATUS];
        }

        $codes = collect(preg_split('/[,\s]+/', $this->expected_status_input) ?: [])
            ->filter(fn (mixed $code): bool => is_numeric($code))
            ->map(fn (mixed $code): int => (int) $code)
            ->filter(fn (int $code): bool => $code >= 100 && $code <= 599)
            ->unique()
            ->values()
            ->all();

        return $codes !== [] ? $codes : MonitorTarget::defaultExpectedStatus();
    }

    private function syncServerAndCommands(MonitorTarget $target): void
    {
        $host = trim($this->ssh_host);

        if ($host === '') {
            $target->server()->delete();
        } else {
            $payload = [
                'host' => $host,
                'port' => $this->ssh_port,
                'username' => trim($this->ssh_username),
            ];

            $existing = $target->server;

            if ($existing !== null && $existing->host !== $host) {
                $payload['host_fingerprint'] = null;
            }

            if ($this->ssh_password !== '') {
                $payload['password'] = $this->ssh_password;
            }

            $target->server()->updateOrCreate([], $payload);
        }

        $ids = MonitorCommand::query()
            ->where('is_enabled', true)
            ->whereIn('id', $this->commandIds)
            ->pluck('id')
            ->all();

        $runner = app(RemoteCommandRunner::class);

        foreach ($this->pendingCustomCommands as $draft) {
            $created = $runner->addToTarget($target, $draft['label'], $draft['command'], $draft['kind']);
            $ids[] = $created->id;
        }

        $target->commands()->sync(array_values(array_unique($ids)));
        $this->pendingCustomCommands = [];
    }

    public function probe(int $id, MonitorEngine $engine): void
    {
        $target = MonitorTarget::query()->findOrFail($id);
        $check = $engine->run($target);

        $unavailable = (bool) data_get($check->payload, 'probe_unavailable');

        unset($this->sites);

        $this->js('window.notify('.json_encode([
            'heading' => $unavailable ? 'Sonda no disponible' : ($check->ok ? 'Sitio operativo' : 'Sitio con incidencia'),
            'text' => $unavailable
                ? 'No se actualizó el UP/DOWN. '.$check->availability_reason
                : (($check->availability_reason ?? '').' · '.($check->total_ms ?? 0).' ms'),
            'variant' => $unavailable ? 'warning' : ($check->ok ? 'success' : 'danger'),
        ]).')');
    }

    public function delete(int $id): void
    {
        $this->authorizeManage();
        MonitorTarget::query()->whereKey($id)->delete();
        unset($this->sites, $this->proxyOptions);
        $this->js('window.notify({ heading: "Sitio eliminado", variant: "success" })');
    }

    public function tick(): void
    {
        unset($this->sites, $this->siteConditions);
    }

    private function authorizeManage(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);
    }

    private function defaultInterval(): int
    {
        $options = app(MonitorSettings::class)->intervalSeconds();

        return in_array(5, $options, true) ? 5 : (int) ($options[0] ?? 5);
    }
}; ?>

<section class="flex w-full flex-col gap-6" wire:poll.10s="tick">
    <div class="flex items-end justify-between gap-3">
        <div>
            <flux:heading size="xl">Sitios web</flux:heading>
            <flux:text class="mt-1">Portales, web services y proxies Linux. Un proxy vinculado dice si la falla es de la app o del front.</flux:text>
        </div>
        @if (auth()->user()?->isAdmin())
            <flux:button icon="plus" wire:click="create">Nuevo sitio</flux:button>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-white/10">
        <table class="w-full min-w-[52rem] table-fixed border-collapse text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-left text-xs font-medium tracking-wide text-zinc-500 uppercase dark:border-white/10 dark:bg-white/5">
                <tr>
                    <th class="w-[40%] px-4 py-3">Destino</th>
                    <th class="w-[22%] px-4 py-3">Estado</th>
                    <th class="w-[16%] px-4 py-3">Servidor</th>
                    <th class="w-[22%] px-4 py-3 text-right">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                @forelse ($this->sites as $site)
                    @php
                        $latest = $site->checks->first();
                        $server = MonitorCopy::webServer($latest?->payload);
                        $reason = $latest?->availability_reason;
                        $hint = MonitorCopy::reasonHint($reason, $site->last_status_code, $site->probeOrigin());
                        $condition = $this->siteConditions[$site->id] ?? ['key' => 'pending'];
                    @endphp
                    <tr class="align-top" wire:key="site-row-{{ $site->id }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('monitor.sites.show', $site) }}" class="group block min-w-0" wire:navigate>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="font-medium group-hover:underline">{{ $site->name }}</span>
                                    <flux:badge size="sm" :color="$site->isExternalOrigin() ? 'sky' : 'zinc'">
                                        {{ MonitorCopy::originLabel($site->probeOrigin()) }}
                                    </flux:badge>
                                    @if ($site->isHealth())
                                        <flux:badge size="sm" color="sky">WS</flux:badge>
                                    @endif
                                    @if ($site->isProxy())
                                        <flux:badge size="sm" color="amber">Proxy</flux:badge>
                                    @elseif ($site->proxy)
                                        <flux:badge size="sm" color="amber">vía {{ $site->proxy->name }}</flux:badge>
                                    @endif
                                    @if ($site->hasSshAccess())
                                        <flux:badge size="sm" color="zinc">SSH</flux:badge>
                                    @endif
                                </div>
                                <div class="mt-0.5 truncate font-mono text-xs text-zinc-500" title="{{ $site->url }}">{{ $site->url }}</div>
                                <div class="mt-0.5 text-[11px] text-zinc-500">Cada {{ $site->interval_seconds }}s</div>
                            </a>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <x-monitor.condition-badge :condition="$condition" />
                                @if (($condition['key'] ?? '') === 'ok')
                                    <span class="text-[11px] text-zinc-500">{{ $site->last_status_code }} · {{ $site->last_total_ms }} ms</span>
                                @endif
                            </div>
                            <div class="mt-1 text-[11px] text-zinc-500">{{ $condition['detail'] ?? MonitorCopy::reason($reason) }}</div>
                            @if ($hint)
                                <div class="mt-1.5 rounded-md bg-zinc-50 px-2 py-1.5 text-[11px] leading-4 text-pretty break-words text-zinc-600 dark:bg-white/5 dark:text-zinc-300">
                                    {{ $hint }}
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="truncate font-medium" title="{{ $server['product'] }}">{{ $server['family'] }}</div>
                            <div class="truncate text-[11px] text-zinc-500" title="{{ $server['product'] }}">{{ $server['product'] }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap justify-end gap-1">
                                <flux:button size="sm" variant="ghost" wire:click="probe({{ $site->id }})" wire:loading.attr="disabled">Probar</flux:button>
                                <flux:button size="sm" variant="ghost" :href="route('monitor.sites.show', $site)" wire:navigate>JSON</flux:button>
                                @if (auth()->user()?->isAdmin())
                                    <flux:button size="sm" variant="ghost" wire:click="edit({{ $site->id }})">Editar</flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="delete({{ $site->id }})" wire:confirm="¿Eliminar este sitio?">Borrar</flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-zinc-500">Añade la primera URL para empezar el monitoreo.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal wire:model="showForm" class="md:w-2xl">
        @if ($showForm)
        <form wire:submit="save" class="space-y-4">
            <flux:heading size="lg">{{ $editingId ? 'Editar destino' : 'Nuevo destino' }}</flux:heading>
            <flux:text size="sm">Primero elija el tipo. El formulario muestra solo lo que ese destino necesita.</flux:text>

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium text-zinc-800 dark:text-zinc-100">¿Qué va a monitorear?</legend>
                @foreach ([
                    ['proxy', 'Proxy Linux', 'Nginx o HAProxy. Sondeo del front y comandos SSH para validar 80/443.'],
                    ['http', 'Sitio web', 'Portal o página. Puede vincularse al proxy que la contiene.'],
                    ['health', 'Web service', 'JSON de salud (/health o /actuator) y sus dependencias.'],
                ] as [$value, $title, $hint])
                    <label @class([
                        'flex cursor-pointer items-start gap-3 rounded-xl border px-3 py-2.5 transition',
                        'border-amber-500 bg-amber-500/10 dark:border-amber-400/70' => $kind === $value && $value === 'proxy',
                        'border-sky-500 bg-sky-500/10 dark:border-sky-400/70' => $kind === $value && $value === 'health',
                        'border-zinc-900 bg-zinc-900/5 dark:border-white/60 dark:bg-white/10' => $kind === $value && $value === 'http',
                        'border-zinc-200 hover:border-zinc-400 dark:border-white/10 dark:hover:border-white/25' => $kind !== $value,
                    ])>
                        <input type="radio" wire:model.live="kind" value="{{ $value }}" class="mt-1">
                        <span>
                            <span class="block font-medium text-zinc-900 dark:text-zinc-100">{{ $title }}</span>
                            <span class="block text-[12px] leading-4 text-zinc-500 dark:text-zinc-400">{{ $hint }}</span>
                        </span>
                    </label>
                @endforeach
            </fieldset>

            @if ($kind === '')
                <div class="flex justify-end">
                    <flux:button type="button" variant="ghost" wire:click="closeForm">Cancelar</flux:button>
                </div>
            @else
                <flux:input wire:model="name" label="Nombre" />
                <flux:input
                    wire:model.live="url"
                    :label="$kind === 'proxy' ? 'URL del proxy (80/443)' : ($kind === 'health' ? 'URL del health' : 'URL HTTPS')"
                    type="url"
                    :description="$kind === 'proxy' ? 'Ej. http://172.24.28.1/ o https://proxy.intra.bcv.org.ve/' : ($kind === 'health' ? 'Ruta /health o /actuator del servicio.' : null)"
                />

                @if ($kind !== 'proxy')
                    <flux:select
                        wire:model="proxy_target_id"
                        label="Proxy que lo contiene"
                        description="Opcional. Si esta app está detrás de un Nginx/HAProxy ya cargado, el dashboard separa falla de proxy vs falla de aplicación."
                    >
                        <flux:select.option value="">Sin proxy vinculado</flux:select.option>
                        @foreach ($this->proxyOptions as $proxy)
                            <flux:select.option value="{{ $proxy->id }}">{{ $proxy->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:select
                    wire:model="probe_origin"
                    label="Sondear desde"
                    :description="$kind === 'proxy' ? 'Casi siempre Interior: el proxy vive en la red BCV.' : 'intra / extra / 172.x = Interior. Portal público = Exterior. Si importa en ambos mundos, cree dos destinos.'"
                >
                    <flux:select.option value="internal">Interior (red BCV / esta máquina)</flux:select.option>
                    <flux:select.option value="external">Exterior (Internet público / VPS)</flux:select.option>
                </flux:select>

                @if ($kind === 'http')
                    <flux:select wire:model="method" label="Método">
                        <flux:select.option value="GET">GET</flux:select.option>
                        <flux:select.option value="HEAD">HEAD</flux:select.option>
                        <flux:select.option value="POST">POST</flux:select.option>
                    </flux:select>
                    <flux:select wire:model.live="availability_mode" label="Criterio de UP" description="¿La página bien (200) o solo que el servidor contestó?">
                        <flux:select.option value="strict">Solo códigos HTTP esperados</flux:select.option>
                        <flux:select.option value="reachable">El servidor responde (cualquier código HTTP)</flux:select.option>
                    </flux:select>
                    @if ($availability_mode === 'reachable')
                        <flux:callout icon="information-circle">
                            UP 500 significa que el host contestó, no que la página funcione. Un 5xx es fallo de la aplicación. Use «Solo códigos HTTP esperados» si un error de servidor debe marcar DOWN.
                        </flux:callout>
                    @endif
                    @if ($availability_mode === 'strict')
                        <flux:input wire:model="expected_status_input" label="Códigos HTTP aceptados" description="Separados por coma. Ejemplo: 200, 301, 302, 403" />
                    @endif
                    <flux:input wire:model="expected_keyword" label="Palabra clave (opcional)" />
                @endif

                <flux:select wire:model="interval_seconds" label="Intervalo" description="Cada cuántos segundos se sondea.">
                    @foreach ($this->intervalOptions as $seconds)
                        <flux:select.option value="{{ $seconds }}">{{ $seconds }} segundos</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($kind !== 'proxy')
                    <flux:input wire:model="timeout_seconds" label="Timeout (s)" type="number" />
                    <flux:checkbox wire:model="verify_ssl" label="Verificar certificado SSL" description="Si solo falla la CA interna, el sistema reintenta y no marca DOWN." />
                @endif

                <div class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-white/10">
                    <div>
                        <flux:heading size="sm">Servidor SSH</flux:heading>
                        <flux:text size="sm">
                            @if ($kind === 'proxy')
                                Host del Nginx/HAProxy. Desde aquí se valida si falló el front (nginx -t, puertos 80/443, recargar). La clave se cifra.
                            @else
                                Opcional. Si lo llena, podrá ejecutar comandos en el servidor de esta app. La clave se cifra.
                            @endif
                        </flux:text>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input wire:model="ssh_host" label="Host SSH" placeholder="{{ $kind === 'proxy' ? 'proxy.intra.bcv.org.ve' : 'ocppws.extra.bcv.org.ve' }}" />
                        <flux:input wire:model="ssh_port" label="Puerto" type="number" />
                        <flux:input wire:model="ssh_username" label="Usuario" autocomplete="off" />
                        <flux:input
                            wire:model="ssh_password"
                            label="{{ $hasStoredPassword ? 'Nueva clave (opcional)' : 'Clave' }}"
                            type="password"
                            autocomplete="new-password"
                            :placeholder="$hasStoredPassword ? 'Clave cifrada guardada' : ''"
                        />
                    </div>
                </div>

                <div class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-white/10">
                    <div>
                        <flux:heading size="sm">{{ $kind === 'proxy' ? 'Comandos del proxy' : 'Comandos de este destino' }}</flux:heading>
                        <flux:text size="sm">
                            @if ($kind === 'proxy')
                                Ya vienen marcados Nginx, HAProxy y chequeos de host. Desmarque lo que no use.
                            @else
                                Marque los precargados o agregue un alias que solo conocen los administradores del servidor.
                            @endif
                        </flux:text>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <flux:text class="font-medium">Consulta</flux:text>
                            @foreach ($this->formCommands->where('kind', 'query') as $command)
                                <label class="flex items-start gap-2 text-sm">
                                    <input type="checkbox" wire:model="commandIds" value="{{ $command->id }}" class="mt-1 rounded border-zinc-300 dark:border-white/20">
                                    <span>
                                        <span class="font-medium">{{ $command->label }}</span>
                                        @if ($command->isCustom())
                                            <flux:badge size="sm" color="sky">Alias</flux:badge>
                                        @endif
                                        <span class="block font-mono text-[11px] text-zinc-500">{{ $command->command }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <div class="space-y-2">
                            <flux:text class="font-medium">Cambio</flux:text>
                            @foreach ($this->formCommands->where('kind', 'change') as $command)
                                <label class="flex items-start gap-2 text-sm">
                                    <input type="checkbox" wire:model="commandIds" value="{{ $command->id }}" class="mt-1 rounded border-zinc-300 dark:border-white/20">
                                    <span>
                                        <span class="font-medium">{{ $command->label }}</span>
                                        @if ($command->isCustom())
                                            <flux:badge size="sm" color="sky">Alias</flux:badge>
                                        @endif
                                        <span class="block font-mono text-[11px] text-zinc-500">{{ $command->command }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-3 rounded-lg bg-zinc-50 p-3 dark:bg-white/5">
                        <div>
                            <flux:text class="font-medium">Agregar comando o alias</flux:text>
                            <flux:text size="sm">Ejemplo: nombre “Estado OCPP” y comando <span class="font-mono">ocpp-status</span>.</flux:text>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <flux:input wire:model="newCommandLabel" label="Nombre en la lista" placeholder="Estado OCPP" />
                            <flux:input wire:model="newCommandText" label="Comando o alias" placeholder="ocpp-status" class="font-mono" />
                        </div>
                        <div class="flex flex-wrap items-end gap-3">
                            <flux:select wire:model="newCommandKind" label="Tipo de comando" class="min-w-40">
                                <flux:select.option value="query">Consulta (solo mira)</flux:select.option>
                                <flux:select.option value="change">Cambio (reinicia o modifica)</flux:select.option>
                            </flux:select>
                            <flux:button type="button" icon="plus" wire:click="addCustomCommandToForm">Agregar a la lista</flux:button>
                        </div>
                        @if ($pendingCustomCommands !== [])
                            <div class="space-y-2">
                                <flux:text size="sm">Se guardarán con el sitio:</flux:text>
                                @foreach ($pendingCustomCommands as $index => $draft)
                                    <div class="flex items-center justify-between gap-2 rounded-md border border-zinc-200 px-3 py-2 text-sm dark:border-white/10">
                                        <span>
                                            <span class="font-medium">{{ $draft['label'] }}</span>
                                            <span class="font-mono text-zinc-500"> · {{ $draft['command'] }}</span>
                                            <flux:badge size="sm" :color="$draft['kind'] === 'change' ? 'amber' : 'zinc'">
                                                {{ $draft['kind'] === 'change' ? 'Cambio' : 'Consulta' }}
                                            </flux:badge>
                                        </span>
                                        <flux:button size="sm" variant="ghost" type="button" wire:click="removePendingCustomCommand({{ $index }})">Quitar</flux:button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="ghost" wire:click="closeForm">Cancelar</flux:button>
                    <flux:button type="submit" variant="primary">Guardar</flux:button>
                </div>
            @endif
        </form>
        @endif
    </flux:modal>
</section>
