<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Staff user & role management (PRD §6.1 "Manage users & roles" — Administrator
 * only). Every action is authorized server-side via UserPolicy, in addition to
 * the route's `can:manage users` guard.
 */
#[Layout('components.layouts.app')]
class UserManagement extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $role = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function createUser(): void
    {
        $this->authorize('create', User::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
            'role' => ['required', Rule::enum(Role::class)],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->assignRole($validated['role']);

        activity('user')
            ->performedOn($user)
            ->causedBy($this->currentUser())
            ->withProperties(['role' => $validated['role']])
            ->log('Created staff user');

        $this->reset('name', 'email', 'password', 'role');
        session()->flash('status', "User {$user->email} created.");
    }

    public function updateRole(int $userId, string $role): void
    {
        $user = User::findOrFail($userId);

        $this->authorize('update', $user);

        $validated = validator(
            ['role' => $role],
            ['role' => ['required', Rule::enum(Role::class)]],
        )->validate();

        $user->syncRoles([$validated['role']]);

        activity('user')
            ->performedOn($user)
            ->causedBy($this->currentUser())
            ->withProperties(['role' => $validated['role']])
            ->log('Changed user role');
    }

    public function render(): View
    {
        return view('livewire.settings.user-management', [
            'users' => User::with('roles')->orderBy('name')->get(),
            'roles' => Role::cases(),
        ]);
    }

    private function currentUser(): ?User
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user;
    }
}
