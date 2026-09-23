<x-layouts::auth.gateway :title="__('Registro')">
    <div class="flex flex-col gap-6">
        <div class="flex w-full flex-col text-center lg:text-left">
            <flux:heading size="xl" level="1">Solicitar acceso</flux:heading>
            <flux:subheading>Cree su cuenta institucional para entrar al monitoreo de portales del BCV.</flux:subheading>
        </div>

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="name"
                :label="__('Nombre')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Nombre completo')"
            />

            <flux:input
                name="email"
                :label="__('Correo institucional')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="nombre@bcv.org.ve"
            />

            <flux:input
                name="password"
                :label="__('Contraseña')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Contraseña')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <flux:input
                name="password_confirmation"
                :label="__('Confirmar contraseña')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirmar contraseña')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    Crear cuenta
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 text-center text-sm text-zinc-400 rtl:space-x-reverse">
            <span>¿Ya tiene una cuenta?</span>
            <flux:link :href="route('login')" wire:navigate>Entrar al monitoreo</flux:link>
        </div>
    </div>
</x-layouts::auth.gateway>
