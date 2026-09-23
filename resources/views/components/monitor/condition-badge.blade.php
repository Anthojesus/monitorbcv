@props([
    'condition' => [],
])

@php
    $key = $condition['key'] ?? 'pending';
    $label = $condition['label'] ?? 'Pendiente';
    $classes = match ($key) {
        'ok' => 'bg-emerald-500 text-white shadow-sm shadow-emerald-500/40',
        'degraded' => 'bg-amber-500 text-white',
        'down' => 'bg-rose-500 text-white shadow-sm shadow-rose-500/40',
        'unmonitored' => 'bg-amber-500 text-white',
        default => 'bg-zinc-500 text-white',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-md px-2 py-0.5 text-xs font-bold', $classes]) }}>
    {{ $label }}
</span>
