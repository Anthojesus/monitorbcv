<x-layouts::auth.gateway :title="__('Acceso')">
    <div class="flex flex-col gap-6">
        <div class="flex w-full flex-col text-center lg:text-left">
            <flux:heading size="xl" level="1">Acceso al Centro de Control</flux:heading>
            <flux:subheading>Use su cuenta institucional para entrar al monitoreo de portales del BCV.</flux:subheading>
        </div>

        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify
            :label="__('Entrar con llave de acceso')"
            :separator="__('O continuar con correo')"
            separator-surface="bg-[#0c1824]"
        />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="email"
                :label="__('Correo institucional')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="nombre@bcv.org.ve"
            />

            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Contraseña')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Contraseña')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('¿Olvidó su contraseña?') }}
                    </flux:link>
                @endif
            </div>

            <flux:checkbox name="remember" :label="__('Recordarme')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    Entrar al monitoreo
                </flux:button>
            </div>
        </form>

        @if (Route::has('register'))
            <div class="space-x-1 text-center text-sm text-zinc-400 rtl:space-x-reverse">
                <span>¿Necesita una cuenta?</span>
                <flux:link :href="route('register')" wire:navigate>Solicitar acceso</flux:link>
            </div>
        @endif
    </div>
</x-layouts::auth.gateway>
