<div wire:keydown.escape.window="cancel">
    <div class="mb-6 flex items-start justify-between">
        <div>
            <nav class="mb-1.5 flex items-center gap-1.5 text-12 text-muted"><span>Registry</span><span>/</span><span class="text-subtle">Tenants</span></nav>
            <h1 class="text-24 font-semibold text-ink">Tenants</h1>
            <p class="text-13 text-muted">Individuals and organisations leasing council land (PRD §4.2).</p>
        </div>
        <button wire:click="create" class="btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
            New tenant
        </button>
    </div>
    <x-toast />

    @if ($showForm)
        <x-modal :title="$editingId ? 'Edit tenant' : 'Add a tenant'" close="cancel">
            <form wire:submit="save">
                <div class="grid grid-cols-1 gap-4 px-5 py-4 sm:grid-cols-2">
                    <div>
                        <label class="fl">Tenant type</label>
                        <select wire:model.live="type" class="input mt-1">
                            <option value="">Select…</option>
                            @foreach ($types as $t)
                                <option value="{{ $t->value }}">{{ $t->label() }}</option>
                            @endforeach
                        </select>
                        @error('type') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="fl">Name</label>
                        <input type="text" wire:model="name" class="input mt-1">
                        @error('name') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>

                    @if ($type === 'organisation')
                        <div>
                            <label class="fl">Company registration no.</label>
                            <input type="text" wire:model="company_reg_no" class="input mt-1">
                            @error('company_reg_no') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="fl">Contact person</label>
                            <input type="text" wire:model="contact_person" class="input mt-1">
                            @error('contact_person') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                    @else
                        <div>
                            <label class="fl">National ID</label>
                            <input type="text" wire:model="national_id" class="input mt-1">
                            @error('national_id') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div>
                        <label class="fl">Mobile</label>
                        <input type="text" wire:model="mobile" class="input mt-1">
                        @error('mobile') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="fl">Email</label>
                        <input type="email" wire:model="email" class="input mt-1">
                        @error('email') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="fl">Postal address</label>
                        <textarea wire:model="postal_address" rows="2" class="input mt-1 h-auto py-2"></textarea>
                        @error('postal_address') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>

                    <label class="flex items-center gap-2 text-13 text-subtle sm:col-span-2">
                        <input type="checkbox" wire:model="sms_opt_out" class="rounded border-line text-brand-500 focus:ring-brand-300">
                        Opted out of SMS reminders (§5.5 — no reminders will be sent to this tenant)
                    </label>
                </div>
                <div class="flex items-center justify-end gap-2 rounded-b-lg border-t border-line-2 bg-sunken px-5 py-3.5">
                    <button type="button" wire:click="cancel" class="btn-subtle">Cancel</button>
                    <button type="submit" class="btn-primary">{{ $editingId ? 'Save changes' : 'Create tenant' }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- ============ Toolbar: filter + chips (design PRD §5.5) ============ --}}
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <label class="relative">
            <span class="absolute inset-y-0 left-2.5 grid place-items-center text-muted">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            </span>
            <input type="text" wire:model.live.debounce.300ms="q" placeholder="Filter tenants"
                class="h-8 w-56 rounded border border-line bg-surface pl-8 pr-3 text-13 placeholder:text-muted focus:border-brand-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-300">
        </label>

        <select wire:model.live="typeFilter" class="chip {{ $typeFilter !== '' ? 'border-brand-500 text-brand-600' : '' }}">
            <option value="">Tenant type</option>
            @foreach ($types as $typeOption)
                <option value="{{ $typeOption->value }}">{{ $typeOption->label() }}</option>
            @endforeach
        </select>

        @if ($q !== '' || $typeFilter !== '')
            <button wire:click="clearFilters" class="btn-subtle h-8 px-2 text-12">Clear filters</button>
        @endif

        <div class="ml-auto text-12 text-muted">{{ $tenants->total() }} tenant{{ $tenants->total() === 1 ? '' : 's' }}</div>
    </div>

    <div class="overflow-hidden rounded-md border border-line bg-surface shadow-card">
        <div class="overflow-x-auto">
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
                    <tr><td colspan="6" class="px-4 py-8 text-center text-[13px] text-muted">
                        @if ($q !== '' || $typeFilter !== '')
                            No tenants match this view — clear the filters.
                        @else
                            No tenants yet — add the first one.
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
        <x-pagination :paginator="$tenants" />
    </div>
</div>
