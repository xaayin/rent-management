<div>
    <div class="mb-6 flex items-start justify-between">
        <div>
            <nav class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted">Registry / Properties</nav>
            <h1 class="text-2xl font-semibold text-ink">Properties</h1>
            <p class="text-[13px] text-muted">Council land parcels and premises (PRD §4.1).</p>
        </div>
        <button wire:click="create"
            class="h-8 rounded bg-brand-500 px-4 text-sm font-medium text-white transition hover:bg-brand-600 active:bg-brand-700">
            New property
        </button>
    </div>
    <x-toast />

    @if ($showForm)
        <div class="mb-8 rounded-md border border-line bg-surface p-6 shadow-card">
            <h2 class="mb-4 text-base font-semibold text-ink">{{ $editingId ? 'Edit property' : 'Add a property' }}</h2>

            <form wire:submit="save" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Name</label>
                    <input type="text" wire:model="name" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                    @error('name') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Land / parcel number</label>
                    <input type="text" wire:model="land_number" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                    @error('land_number') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Size (ft²)</label>
                    <input type="number" wire:model="size_sqft" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                    @error('size_sqft') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Usage type</label>
                    <select wire:model="usage_type" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                        <option value="">Select…</option>
                        @foreach ($usageTypes as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    @error('usage_type') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Location notes</label>
                    <textarea wire:model="location_notes" rows="2" class="w-full rounded border border-line bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300"></textarea>
                    @error('location_notes') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>
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
                    <th class="px-4 py-2 font-semibold">Land no.</th>
                    <th class="px-4 py-2 font-semibold">Usage</th>
                    <th class="px-4 py-2 text-right font-semibold">Size (ft²)</th>
                    <th class="px-4 py-2 font-semibold">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($properties as $property)
                    <tr class="border-b border-line-2 last:border-0 hover:bg-hover">
                        <td class="px-4 py-3 font-medium text-ink">{{ $property->name }}</td>
                        <td class="px-4 py-3 text-subtle">{{ $property->land_number }}</td>
                        <td class="px-4 py-3 text-subtle">{{ $property->usage_type->label() }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink">{{ number_format($property->size_sqft) }}</td>
                        <td class="px-4 py-3">
                            @if ($property->isArchived())
                                <span class="inline-flex items-center rounded-sm px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-subtle" style="background:#EBECF0">Archived</span>
                            @else
                                <span class="inline-flex items-center rounded-sm bg-success-bg px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-success-fg">Active</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="edit({{ $property->id }})" class="rounded px-2 py-1 text-[13px] font-medium text-brand-600 hover:bg-hover">Edit</button>
                            @if ($property->isArchived())
                                <button wire:click="restore({{ $property->id }})" class="rounded px-2 py-1 text-[13px] font-medium text-subtle hover:bg-hover">Restore</button>
                            @else
                                <button wire:click="archive({{ $property->id }})" class="rounded px-2 py-1 text-[13px] font-medium text-subtle hover:bg-hover">Archive</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-[13px] text-muted">No properties yet — add the first one.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
