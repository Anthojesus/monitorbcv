<?php

use App\Models\MonitorIntervalOption;
use App\Services\Monitoring\MonitorSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Ajustes de monitoreo')] class extends Component
{
    public int $chart_history_max_days = 30;

    public int $new_interval_seconds = 5;

    public function mount(MonitorSettings $settings): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);
        $this->chart_history_max_days = $settings->chartHistoryMaxDays();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, MonitorIntervalOption>
     */
    #[Computed]
    public function intervals()
    {
        return MonitorIntervalOption::query()->orderBy('seconds')->get();
    }

    public function saveHistoryDays(MonitorSettings $settings): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        $validated = $this->validate([
            'chart_history_max_days' => 'required|integer|min:1|max:365',
        ]);

        $settings->setChartHistoryMaxDays((int) $validated['chart_history_max_days']);
        Flux::toast(variant: 'success', text: 'Tope de historial actualizado.');
    }

    public function addInterval(MonitorSettings $settings): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        $validated = $this->validate([
            'new_interval_seconds' => 'required|integer|min:1|max:60',
        ]);

        $settings->addInterval((int) $validated['new_interval_seconds']);
        $this->new_interval_seconds = 5;
        unset($this->intervals);
        Flux::toast(variant: 'success', text: 'Intervalo agregado a la lista.');
    }

    public function removeInterval(int $id, MonitorSettings $settings): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        $settings->removeInterval($id);
        unset($this->intervals);
        Flux::toast(variant: 'success', text: 'Intervalo quitado.');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout heading="Monitoreo" subheading="Intervalos permitidos por sitio y tope del historial de los gráficos." :wide="true">
        <div class="space-y-8">
            <form wire:submit="saveHistoryDays" class="space-y-4">
                <flux:heading size="sm">Historial de gráficos</flux:heading>
                <flux:text size="sm">El usuario podrá filtrar hasta este máximo. El valor inicial recomendado es 30 días.</flux:text>
                <flux:input wire:model="chart_history_max_days" label="Días máximos hacia atrás" type="number" min="1" max="365" />
                <flux:button type="submit" variant="primary">Guardar tope</flux:button>
            </form>

            <div class="space-y-4">
                <div>
                    <flux:heading size="sm">Intervalos de sondeo (segundos)</flux:heading>
                    <flux:text size="sm">Estas opciones aparecen al crear o editar un sitio. Valores entre 1 y 60.</flux:text>
                </div>

                <div class="flex flex-wrap gap-2">
                    @foreach ($this->intervals as $option)
                        <div class="inline-flex items-center gap-1 rounded-lg border border-zinc-200 px-2 py-1 text-sm dark:border-white/10">
                            <span class="font-mono">{{ $option->seconds }}s</span>
                            <flux:button size="sm" variant="ghost" wire:click="removeInterval({{ $option->id }})" wire:confirm="¿Quitar {{ $option->seconds }} segundos de la lista?">
                                Quitar
                            </flux:button>
                        </div>
                    @endforeach
                </div>

                <form wire:submit="addInterval" class="flex flex-wrap items-end gap-3">
                    <flux:input wire:model="new_interval_seconds" label="Agregar segundos" type="number" min="1" max="60" class="w-40" />
                    <flux:button type="submit" icon="plus">Agregar</flux:button>
                </form>
            </div>
        </div>
    </x-pages::settings.layout>
</section>
