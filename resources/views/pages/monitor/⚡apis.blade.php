<?php

use App\Models\MonitorTarget;
use App\Services\Monitoring\FastApiProbe;
use App\Services\Monitoring\MonitorCopy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Logs de API')] class extends Component
{
    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function feeds(): array
    {
        $probe = app(FastApiProbe::class);

        return [
            'internal' => $probe->logs(MonitorTarget::ORIGIN_INTERNAL),
            'external' => $probe->logs(MonitorTarget::ORIGIN_EXTERNAL),
        ];
    }

    public function tick(): void
    {
        unset($this->feeds);
    }
}; ?>

<section class="flex w-full flex-col gap-6" wire:poll.5s="tick">
    <div>
        <flux:heading size="xl">Logs de las sondas</flux:heading>
        <flux:text class="mt-1">
            Interior (esta red / FastAPI local) y Exterior (VPS). /health es público; /v1/checks y /v1/logs exigen Bearer.
            Si el VPS aún no tiene /v1/logs, actualice el main.py con la guía de contingencia.
        </flux:text>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach (['internal' => 'Interior', 'external' => 'Exterior'] as $origin => $label)
            @php
                $feed = $this->feeds[$origin];
                $apiOk = (bool) data_get($feed, 'runtime.api.ok');
                $pythonOk = (bool) data_get($feed, 'runtime.python.ok');
            @endphp
            <flux:card class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <flux:heading size="lg">API {{ $label }}</flux:heading>
                        <flux:text class="font-mono text-xs">{{ $feed['url'] !== '' ? $feed['url'] : 'sin URL' }}</flux:text>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <span @class([
                            'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold text-white',
                            'bg-emerald-500 shadow-[0_0_14px] shadow-emerald-400/70' => $apiOk,
                            'bg-rose-600' => ! $apiOk,
                        ])>API · {{ $apiOk ? 'OK' : 'DOWN' }}</span>
                        <span @class([
                            'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold text-white',
                            'bg-emerald-500 shadow-[0_0_14px] shadow-emerald-400/70' => $pythonOk,
                            'bg-rose-600' => ! $pythonOk,
                        ])>Sonda · {{ $pythonOk ? 'OK' : 'DOWN' }}</span>
                    </div>
                </div>

                <p class="text-sm text-zinc-500">{{ $feed['hint'] }}</p>

                @if ($feed['events'] === [])
                    <flux:callout icon="information-circle">
                        Aún no hay eventos. Aparecen al sondear un destino de este origen o si la API responde 4xx/5xx.
                    </flux:callout>
                @else
                    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-white/10">
                        <table class="w-full min-w-[28rem] text-left text-sm">
                            <thead class="bg-zinc-50 text-xs font-medium tracking-wide text-zinc-500 uppercase dark:bg-white/5">
                                <tr>
                                    <th class="px-3 py-2">Cuando</th>
                                    <th class="px-3 py-2">Qué</th>
                                    <th class="px-3 py-2">Detalle</th>
                                    <th class="px-3 py-2">Resultado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-white/10">
                                @foreach ($feed['events'] as $event)
                                    @php
                                        $when = data_get($event, 'at');
                                        $kind = data_get($event, 'kind');
                                        $ok = data_get($event, 'ok');
                                        $status = data_get($event, 'status');
                                    @endphp
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-[11px] text-zinc-500">
                                            {{ is_string($when) ? \Illuminate\Support\Carbon::parse($when)->timezone(config('app.timezone'))->format('H:i:s') : '—' }}
                                        </td>
                                        <td class="px-3 py-2">
                                            {{ $kind === 'check' ? 'Sondeo' : strtoupper((string) data_get($event, 'method', 'HTTP')) }}
                                        </td>
                                        <td class="min-w-0 px-3 py-2">
                                            <div class="truncate font-medium" title="{{ data_get($event, 'target') ?: data_get($event, 'path') }}">
                                                {{ data_get($event, 'target') ?: data_get($event, 'path') ?: '—' }}
                                            </div>
                                            @if (data_get($event, 'url'))
                                                <div class="truncate font-mono text-[11px] text-zinc-500" title="{{ data_get($event, 'url') }}">{{ data_get($event, 'url') }}</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">
                                            @if ($kind === 'check')
                                                <span @class([
                                                    'inline-flex rounded-md px-2 py-0.5 text-xs font-bold text-white',
                                                    'bg-emerald-500' => $ok === true,
                                                    'bg-rose-500' => $ok !== true,
                                                ])>{{ $ok === true ? 'OK' : 'FAIL' }}{{ $status ? ' '.$status : '' }}</span>
                                                <div class="mt-0.5 text-[11px] text-zinc-500">
                                                    {{ MonitorCopy::reason(is_string(data_get($event, 'reason')) ? data_get($event, 'reason') : null) }}
                                                    @if (data_get($event, 'ms') !== null)
                                                        · {{ data_get($event, 'ms') }} ms
                                                    @endif
                                                </div>
                                            @else
                                                <span class="font-mono text-xs">{{ $status ?? '—' }}</span>
                                                @if (data_get($event, 'ms') !== null)
                                                    <span class="text-[11px] text-zinc-500">· {{ data_get($event, 'ms') }} ms</span>
                                                @endif
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </flux:card>
        @endforeach
    </div>
</section>
