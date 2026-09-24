<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800">
    <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden mr-2" icon="bars-2" inset="left" />

        <x-app-logo href="{{ route('dashboard') }}" wire:navigate />

        <flux:navbar class="-mb-px max-lg:hidden">
            <flux:navbar.item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                Centro de Control
            </flux:navbar.item>
            <flux:navbar.item icon="globe-alt" :href="route('monitor.sites')" :current="request()->routeIs('monitor.sites*')" wire:navigate>
                Sitios
            </flux:navbar.item>
            <flux:navbar.item icon="queue-list" :href="route('monitor.apis')" :current="request()->routeIs('monitor.apis')" wire:navigate>
                Logs de API
            </flux:navbar.item>
            <flux:navbar.item icon="book-open-text" :href="route('documentation')" :current="request()->routeIs('documentation')" wire:navigate>
                Documentación
            </flux:navbar.item>
        </flux:navbar>

        <flux:spacer />

        <flux:navbar class="me-1.5 space-x-0.5 rtl:space-x-reverse py-0!">
            <flux:tooltip :content="__('Search')" position="bottom">
                <flux:navbar.item class="!h-10 [&>div>svg]:size-5" icon="magnifying-glass" href="#" :label="__('Search')" />
            </flux:tooltip>
            <flux:tooltip content="Repositorio" position="bottom">
                <flux:navbar.item class="h-10 max-lg:hidden [&>div>svg]:size-5" icon="folder-git-2" href="{{ config('app.repository') }}" target="_blank" rel="noopener noreferrer" label="Repositorio" />
            </flux:tooltip>
            <flux:tooltip content="Documentación" position="bottom">
                <flux:navbar.item class="h-10 max-lg:hidden [&>div>svg]:size-5" icon="book-open-text" :href="route('documentation')" wire:navigate label="Documentación" />
            </flux:tooltip>
        </flux:navbar>

        <x-desktop-user-menu />
    </flux:header>

    <!-- Mobile Menu -->
    <flux:sidebar collapsible="mobile" sticky class="lg:hidden border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.header>
            <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
            <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
        </flux:sidebar.header>

        <flux:sidebar.nav>
            <flux:sidebar.group :heading="__('Platform')">
                <flux:sidebar.item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    Centro de Control
                </flux:sidebar.item>
                <flux:sidebar.item icon="globe-alt" :href="route('monitor.sites')" :current="request()->routeIs('monitor.sites*')" wire:navigate>
                    Sitios
                </flux:sidebar.item>
                <flux:sidebar.item icon="queue-list" :href="route('monitor.apis')" :current="request()->routeIs('monitor.apis')" wire:navigate>
                    Logs de API
                </flux:sidebar.item>
            </flux:sidebar.group>
        </flux:sidebar.nav>

        <flux:spacer />

        <flux:sidebar.nav>
            <flux:sidebar.item icon="folder-git-2" href="{{ config('app.repository') }}" target="_blank" rel="noopener noreferrer">
                Repositorio
            </flux:sidebar.item>
            <flux:sidebar.item icon="book-open-text" :href="route('documentation')" :current="request()->routeIs('documentation')" wire:navigate>
                Documentación
            </flux:sidebar.item>
        </flux:sidebar.nav>
    </flux:sidebar>

    {{ $slot }}

    <x-sileo-toaster />

    @persist('toast')
    <flux:toast.group>
        <flux:toast />
    </flux:toast.group>
    @endpersist

    @fluxScripts
</body>
</html>
