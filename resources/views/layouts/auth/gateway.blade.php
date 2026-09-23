@props([
    'title' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        <style>
            @keyframes gateway-scan {
                0% { transform: translateX(-12%); opacity: .15; }
                50% { opacity: .4; }
                100% { transform: translateX(112%); opacity: .15; }
            }
            .gateway-scan { animation: gateway-scan 7s linear infinite; }
            @media (prefers-reduced-motion: reduce) {
                .gateway-scan { animation: none; }
            }
        </style>
    </head>
    <body class="min-h-screen bg-[#061018] text-zinc-100 antialiased">
        <div class="relative isolate min-h-svh overflow-hidden">
            <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_18%_20%,rgba(201,162,39,.16),transparent_32%),radial-gradient(circle_at_82%_12%,rgba(0,61,165,.22),transparent_28%),linear-gradient(180deg,#07131d_0%,#0a1a28_52%,#061018_100%)]"></div>
            <div class="pointer-events-none absolute inset-0 opacity-[0.18] [background-image:linear-gradient(rgba(255,255,255,.07)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,.07)_1px,transparent_1px)] [background-size:56px_56px]"></div>
            <div class="gateway-scan pointer-events-none absolute inset-y-0 left-0 w-1/3 bg-linear-to-r from-transparent via-amber-200/10 to-transparent"></div>

            <div class="relative z-10 mx-auto grid min-h-svh w-full max-w-7xl items-center gap-10 px-5 py-10 lg:grid-cols-[1.15fr_.85fr] lg:gap-16 lg:px-10 lg:py-12">
                <section class="flex flex-col items-center text-center lg:items-start lg:text-left">
                    <div class="relative mb-8">
                        <div class="absolute inset-[-18%] rounded-full bg-amber-300/15 blur-3xl"></div>
                        <div class="relative overflow-hidden rounded-full ring-2 ring-amber-200/50 ring-offset-8 ring-offset-[#07131d] shadow-[0_20px_80px_rgba(201,162,39,.22)]">
                            <x-app-logo-icon class="size-36 sm:size-44 lg:size-52" />
                        </div>
                    </div>

                    <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-amber-200/80">Banco Central de Venezuela</p>
                    <h1 class="mt-3 max-w-xl text-4xl font-semibold tracking-tight text-white sm:text-5xl lg:text-6xl">
                        Monitoreo de portales
                    </h1>
                    <p class="mt-4 max-w-xl text-base leading-7 text-zinc-300/90 sm:text-lg">
                        Centro de Control institucional para disponibilidad HTTPS, latencia de red, certificados TLS y servicios internos. El estado de cada portal se valida en segundos.
                    </p>

                    <dl class="mt-8 grid w-full max-w-xl gap-3 sm:grid-cols-3">
                        <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-left backdrop-blur-sm">
                            <dt class="text-[11px] uppercase tracking-wider text-zinc-400">Disponibilidad</dt>
                            <dd class="mt-1 text-sm font-medium text-white">UP / DOWN en tiempo real</dd>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-left backdrop-blur-sm">
                            <dt class="text-[11px] uppercase tracking-wider text-zinc-400">Latencia</dt>
                            <dd class="mt-1 text-sm font-medium text-white">ms, TTFB y red</dd>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-left backdrop-blur-sm">
                            <dt class="text-[11px] uppercase tracking-wider text-zinc-400">Seguridad</dt>
                            <dd class="mt-1 text-sm font-medium text-white">TLS y cabeceras</dd>
                        </div>
                    </dl>

                    <p class="mt-8 text-xs text-zinc-500">
                        Desarrollado por DPD - Departamento Procesamiento de Datos
                    </p>
                </section>

                <section class="w-full">
                    <div class="mx-auto w-full max-w-md rounded-3xl border border-white/10 bg-[#0c1824]/82 p-6 shadow-[0_24px_80px_rgba(0,0,0,.35)] backdrop-blur-xl sm:p-8">
                        {{ $slot }}
                    </div>
                </section>
            </div>
        </div>

        <x-sileo-toaster />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
