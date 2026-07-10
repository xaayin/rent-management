<div wire:keydown.escape.window="cancel">
    <div class="mb-6 flex items-start justify-between">
        <div>
            <nav class="mb-1.5 flex items-center gap-1.5 text-12 text-muted"><span>Registry</span><span>/</span><span class="text-subtle">Properties</span></nav>
            <h1 class="text-24 font-semibold text-ink">Properties</h1>
            <p class="text-13 text-muted">Council land parcels and premises (PRD §4.1).</p>
        </div>
        <button wire:click="create" class="btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
            New property
        </button>
    </div>
    <x-toast />

    @if ($showForm)
        <x-modal :title="$editingId ? 'Edit property' : 'Add a property'" close="cancel">
            <form wire:submit="save">
                <div class="grid grid-cols-1 gap-4 px-5 py-4 sm:grid-cols-2">
                    <div>
                        <label class="fl">Name</label>
                        <input type="text" wire:model="name" class="input mt-1">
                        @error('name') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="fl">Land / parcel number</label>
                        <input type="text" wire:model="land_number" class="input mt-1">
                        @error('land_number') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="fl">Size (ft²)</label>
                        <input type="number" wire:model="size_sqft" class="input mt-1 text-right tabular-nums">
                        @error('size_sqft') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="fl">Usage type</label>
                        <select wire:model="usage_type" class="input mt-1">
                            <option value="">Select…</option>
                            @foreach ($usageTypes as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        @error('usage_type') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="fl">Location notes</label>
                        <textarea wire:model="location_notes" rows="2" class="input mt-1 h-auto py-2"></textarea>
                        @error('location_notes') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2 rounded-b-lg border-t border-line-2 bg-sunken px-5 py-3.5">
                    <button type="button" wire:click="cancel" class="btn-subtle">Cancel</button>
                    <button type="submit" class="btn-primary">{{ $editingId ? 'Save changes' : 'Create property' }}</button>
                </div>
            </form>
        </x-modal>
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
