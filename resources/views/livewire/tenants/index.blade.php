<div>
    <div class="mb-6 flex items-start justify-between">
        <div>
            <nav class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted">Registry / Tenants</nav>
            <h1 class="text-2xl font-semibold text-ink">Tenants</h1>
            <p class="text-[13px] text-muted">Individuals and organisations leasing council land (PRD §4.2).</p>
        </div>
        <button wire:click="create"
            class="h-8 rounded bg-brand-500 px-4 text-sm font-medium text-white transition hover:bg-brand-600 active:bg-brand-700">
            New tenant
        </button>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded border border-success-fg/20 bg-success-bg px-3 py-2 text-[13px] text-success-fg">
            {{ session('status') }}
        </div>
    @endif

    @if ($showForm)
        <div class="mb-8 rounded-md border border-line bg-surface p-6 shadow-card">
            <h2 class="mb-4 text-base font-semibold text-ink">{{ $editingId ? 'Edit tenant' : 'Add a tenant' }}</h2>

            <form wire:submit="save" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Tenant type</label>
                    <select wire:model.live="type" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                        <option value="">Select…</option>
                        @foreach ($types as $t)
                            <option value="{{ $t->value }}">{{ $t->label() }}</option>
                        @endforeach
                    </select>
                    @error('type') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Name</label>
                    <input type="text" wire:model="name" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                    @error('name') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>

                @if ($type === 'organisation')
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Company registration no.</label>
                        <input type="text" wire:model="company_reg_no" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                        @error('company_reg_no') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Contact person</label>
                        <input type="text" wire:model="contact_person" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                        @error('contact_person') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">National ID</label>
                        <input type="text" wire:model="national_id" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                        @error('national_id') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Mobile</label>
                    <input type="text" wire:model="mobile" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                    @error('mobile') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Email</label>
                    <input type="email" wire:model="email" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                    @error('email') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Postal address</label>
                    <textarea wire:model="postal_address" rows="2" class="w-full rounded border border-line bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300"></textarea>
                    @error('postal_address') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-center gap-2 text-[13px] text-subtle sm:col-span-2">
                    <input type="checkbox" wire:model="sms_opt_out" class="rounded border-line text-brand-500 focus:ring-brand-300">
                    Opted out of SMS reminders (§5.5 — no reminders will be sent to this tenant)
                </label>

                <div class="flex gap-2 sm:col-span-2">
                    <button type="submit" class="h-8 rounded bg-brand-500 px-4 text-sm font-medium text-white transition hover:bg-brand-600 active:bg-brand-700">Save</button>
                    <button type="button" wire:click="cancel" class="h-8 rounded px-4 text-sm font-medium text-subtle transition hover:bg-hover">Cancel</button>
                </div>
            </form>
        </div>
    @endif

    <div class="overflow-x-auto rounded-md border border-line bg-surface shadow-card">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-line bg-sunken text-[11px] uppercase tracking-wide text-muted">
                    <th class="px-4 py-2 font-semibold">Name</th>
                    <th class="px-4 py-2 font-semibold">Type</th>
                    <th class="px-4 py-2 font-semibold">Registry no.</th>
                    <th class="px-4 py-2 font-semibold">Mobile</th>
                    <th class="px-4 py-2 text-right font-semibold">Leases</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tenants as $tenant)
                    <tr class="border-b border-line-2 last:border-0 hover:bg-hover">
                        <td class="px-4 py-3 font-medium text-ink">{{ $tenant->name }}</td>
                        <td class="px-4 py-3">
                            @if ($tenant->isOrganisation())
                                <span class="inline-flex items-center rounded-sm bg-discovery-bg px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-discovery-fg">Organisation</span>
                            @else
                                <span class="inline-flex items-center rounded-sm bg-info-bg px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-info-fg">Individual</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-subtle">{{ $tenant->registryNumber() }}</td>
                        <td class="px-4 py-3 text-subtle">{{ $tenant->mobile }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink">{{ $tenant->leases_count }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @can(\App\Enums\Permission::ViewReports->value)
                                <a href="{{ route('tenants.statement', $tenant) }}" class="rounded px-2 py-1 text-[13px] font-medium text-subtle hover:bg-hover">Statement</a>
                            @endcan
                            <button wire:click="edit({{ $tenant->id }})" class="rounded px-2 py-1 text-[13px] font-medium text-brand-600 hover:bg-hover">Edit</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-[13px] text-muted">No tenants yet — add the first one.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
