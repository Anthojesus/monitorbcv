<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Usuarios')] class extends Component
{
    use PasswordValidationRules, ProfileValidationRules;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $role = User::ROLE_MONITOR;

    public bool $can_run_commands = false;

    public function mount(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    #[Computed]
    public function users()
    {
        return User::query()->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->reset('name', 'email', 'password', 'password_confirmation');
        $this->role = User::ROLE_MONITOR;
        $this->can_run_commands = false;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $user = User::query()->findOrFail($id);
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->password_confirmation = '';
        $this->role = $user->role;
        $this->can_run_commands = $user->can_run_commands;
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        $rules = [
            ...$this->profileRules($this->editingId),
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_MONITOR])],
            'can_run_commands' => ['boolean'],
        ];

        if ($this->editingId === null || $this->password !== '') {
            $rules['password'] = $this->passwordRules();
        }

        $validated = $this->validate($rules);

        if ($this->wouldLeaveNoAdmin($validated['role'])) {
            $this->addError('role', 'Debe quedar al menos un administrador.');

            return;
        }

        $payload = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'can_run_commands' => $validated['role'] === User::ROLE_ADMIN || $this->can_run_commands,
        ];

        if ($this->editingId === null || $this->password !== '') {
            $payload['password'] = $validated['password'];
        }

        if ($this->editingId === null) {
            User::query()->create($payload);
        } else {
            User::query()->findOrFail($this->editingId)->update($payload);
        }

        $this->showForm = false;
        unset($this->users);
        Flux::toast(variant: 'success', text: 'Usuario guardado.');
    }

    public function delete(int $id): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);

        $user = User::query()->findOrFail($id);

        if ($user->id === Auth::id()) {
            $this->js('window.notify({ heading: "No puede eliminarse a sí mismo", variant: "danger" })');

            return;
        }

        if ($user->isAdmin() && User::query()->where('role', User::ROLE_ADMIN)->count() <= 1) {
            $this->js('window.notify({ heading: "Debe quedar un administrador", variant: "danger" })');

            return;
        }

        $user->delete();
        unset($this->users);
        Flux::toast(variant: 'success', text: 'Usuario eliminado.');
    }

    private function wouldLeaveNoAdmin(string $newRole): bool
    {
        if ($newRole === User::ROLE_ADMIN) {
            return false;
        }

        $adminQuery = User::query()->where('role', User::ROLE_ADMIN);

        if ($this->editingId !== null) {
            $adminQuery->whereKeyNot($this->editingId);
        }

        return $adminQuery->doesntExist();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout heading="Usuarios" subheading="Administradores gestionan la app. Usuarios Monitoreo solo ven el radar; los comandos se habilitan uno a uno." :wide="true">
        <div class="space-y-4">
            <div class="flex justify-end">
                <flux:button icon="plus" wire:click="create">Nuevo usuario</flux:button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-white/10">
                <table class="w-full min-w-[36rem] table-fixed border-collapse text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-left text-xs font-medium tracking-wide text-zinc-500 uppercase dark:border-white/10 dark:bg-white/5">
                        <tr>
                            <th class="w-[36%] px-4 py-3">Usuario</th>
                            <th class="w-[28%] px-4 py-3">Rol</th>
                            <th class="w-[18%] px-4 py-3">Comandos</th>
                            <th class="w-[18%] px-4 py-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                        @foreach ($this->users as $user)
                            <tr wire:key="user-{{ $user->id }}">
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ $user->name }}</div>
                                    <div class="truncate text-xs text-zinc-500">{{ $user->email }}</div>
                                </td>
                                <td class="px-4 py-3">{{ $user->roleLabel() }}</td>
                                <td class="px-4 py-3">{{ $user->canRunCommands() ? 'Sí' : 'No' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex justify-end gap-1">
                                        <flux:button size="sm" variant="ghost" wire:click="edit({{ $user->id }})">Editar</flux:button>
                                        @if ($user->id !== auth()->id())
                                            <flux:button size="sm" variant="ghost" wire:click="delete({{ $user->id }})" wire:confirm="¿Eliminar este usuario?">Borrar</flux:button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <flux:modal wire:model="showForm" class="md:w-xl">
            <form wire:submit="save" class="space-y-4">
                <flux:heading size="lg">{{ $editingId ? 'Editar usuario' : 'Nuevo usuario' }}</flux:heading>
                <flux:input wire:model="name" label="Nombre" />
                <flux:input wire:model="email" label="Correo" type="email" />
                <flux:select wire:model.live="role" label="Rol">
                    <flux:select.option value="{{ \App\Models\User::ROLE_ADMIN }}">Administrador</flux:select.option>
                    <flux:select.option value="{{ \App\Models\User::ROLE_MONITOR }}">Usuario Monitoreo</flux:select.option>
                </flux:select>
                @if ($role === \App\Models\User::ROLE_MONITOR)
                    <flux:checkbox wire:model="can_run_commands" label="Puede ejecutar comandos SSH" description="Solo los destinos ya configurados. No puede crear sitios ni editar la lista de comandos." />
                @endif
                <flux:input wire:model="password" label="{{ $editingId ? 'Nueva clave (opcional)' : 'Clave' }}" type="password" autocomplete="new-password" />
                <flux:input wire:model="password_confirmation" label="Confirmar clave" type="password" autocomplete="new-password" />
                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="ghost" wire:click="$set('showForm', false)">Cancelar</flux:button>
                    <flux:button type="submit" variant="primary">Guardar</flux:button>
                </div>
            </form>
        </flux:modal>
    </x-pages::settings.layout>
</section>
