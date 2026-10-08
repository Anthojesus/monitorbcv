@props([
    'hostOk' => null,
    'pageOk' => null,
])

@if ($hostOk !== null || $pageOk !== null)
    <span {{ $attributes->class(['inline-flex flex-wrap items-center gap-1']) }}>
        <span @class([
            'inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
            'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' => $hostOk === true,
            'bg-rose-500/15 text-rose-700 dark:text-rose-300' => $hostOk === false,
            'bg-zinc-500/15 text-zinc-500' => $hostOk === null,
        ])>Host {{ $hostOk === true ? 'OK' : ($hostOk === false ? 'no' : '—') }}</span>
        <span @class([
            'inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
            'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' => $pageOk === true,
            'bg-rose-500/15 text-rose-700 dark:text-rose-300' => $pageOk === false,
            'bg-zinc-500/15 text-zinc-500' => $pageOk === null,
        ])>Página {{ $pageOk === true ? 'OK' : ($pageOk === false ? 'no' : '—') }}</span>
    </span>
@endif
