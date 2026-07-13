<div>
    <nav class="mb-2 text-[11px] font-bold uppercase tracking-[0.08em] text-muted">Settings / Users</nav>
    <h1 class="mb-1 text-2xl font-semibold text-ink">Users &amp; roles</h1>
    <p class="mb-6 text-[13px] text-muted">Create council staff accounts and assign each a role (PRD §6.1).</p>
    <x-toast />

    {{-- Create user --}}
    <div class="mb-8 rounded-md border border-line bg-surface p-6 shadow-card">
        <h2 class="mb-4 text-base font-semibold text-ink">Add a staff user</h2>

        <form wire:submit="createUser" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="name" class="mb-1 block text-[11px] font-bold uppercase tracking-[0.08em] text-subtle">Name</label>
                <input id="name" type="text" wire:model="name"
                    class="input ">
                @error('name') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="email" class="mb-1 block text-[11px] font-bold uppercase tracking-[0.08em] text-subtle">Email</label>
                <input id="email" type="email" wire:model="email"
                    class="input ">
                @error('email') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password" class="mb-1 block text-[11px] font-bold uppercase tracking-[0.08em] text-subtle">Temporary password</label>
                <input id="password" type="password" wire:model="password" autocomplete="new-password"
                    class="input ">
                @error('password') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-bold uppercase tracking-[0.08em] text-subtle">Role</label>
                <x-select wire:model="role" placeholder="Select a role…"
                    :options="collect($roles)->mapWithKeys(fn ($r) => [$r->value => $r->label()])" />
                @error('role') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <button type="submit"
                    class="h-8 rounded bg-brand-500 px-4 text-sm font-medium text-white transition hover:bg-brand-600 active:bg-brand-700">
                    Create user
                </button>
            </div>
        </form>
    </div>

    {{-- Users list --}}
    <div class="overflow-x-auto rounded-md border border-line bg-surface shadow-card">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-line">
                    <th class="th text-left">Name</th>
                    <th class="th text-left">Email</th>
                    <th class="th text-left">Role</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $u)
                    @php $current = $u->roles->first()?->name; @endphp
                    <tr class="border-b border-line-2 last:border-0 hover:bg-hover">
                        <td class="px-4 py-3 font-medium text-ink">{{ $u->name }}</td>
                        <td class="px-4 py-3 text-subtle">{{ $u->email }}</td>
                        <td class="px-4 py-3">
                            <x-select class="w-48" :value="$current"
                                :options="collect($roles)->mapWithKeys(fn ($r) => [$r->value => $r->label()])"
                                x-on:changed="$wire.updateRole({{ $u->id }}, $event.detail)" />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
