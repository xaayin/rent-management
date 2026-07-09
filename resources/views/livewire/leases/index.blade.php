<div>
    <div class="mb-6 flex items-start justify-between">
        <div>
            <nav class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted">Registry / Leases</nav>
            <h1 class="text-2xl font-semibold text-ink">Leases</h1>
            <p class="text-[13px] text-muted">Lease agreements linking a property to a tenant (PRD §4.3).</p>
        </div>
        <button wire:click="create"
            class="h-8 rounded bg-brand-500 px-4 text-sm font-medium text-white transition hover:bg-brand-600 active:bg-brand-700">
            New lease
        </button>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded border border-success-fg/20 bg-success-bg px-3 py-2 text-[13px] text-success-fg">
            {{ session('status') }}
        </div>
    @endif

    @if ($showForm)
        <div class="mb-8 rounded-md border border-line bg-surface p-6 shadow-card">
            <h2 class="mb-4 text-base font-semibold text-ink">{{ $editingId ? 'Edit lease' : 'Add a lease' }}</h2>

            <form wire:submit="save" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Agreement number</label>
                    <input type="text" wire:model="agreement_number" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                    @error('agreement_number') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Status</label>
                    <select wire:model="status" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                        <option value="draft">Draft</option>
                        <option value="active">Active</option>
                    </select>
                    @error('status') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Property</label>
                    <select wire:model="property_id" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                        <option value="">Select…</option>
                        @foreach ($properties as $property)
                            <option value="{{ $property->id }}">{{ $property->name }} ({{ $property->land_number }})</option>
                        @endforeach
                    </select>
                    @error('property_id') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Tenant</label>
                    <select wire:model="tenant_id" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                        <option value="">Select…</option>
                        @foreach ($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    @error('tenant_id') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Agreement date</label>
                    <input type="date" wire:model="agreement_date" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                    @error('agreement_date') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Duration (years)</label>
                    <input type="number" wire:model.blur="duration_years" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                    @error('duration_years') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Lease start date</label>
                    <input type="date" wire:model.blur="start_date" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                    @error('start_date') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Rent-start date</label>
                    <input type="date" wire:model="rent_start_date" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                    @error('rent_start_date') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Expiry date <span class="normal-case text-muted">(auto from start + duration)</span></label>
                    <input type="date" wire:model="expiry_date" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                    @error('expiry_date') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Rent basis</label>
                    <select wire:model.live="rent_basis" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                        <option value="">Select…</option>
                        @foreach ($rentBases as $basis)
                            <option value="{{ $basis->value }}">{{ $basis->label() }}</option>
                        @endforeach
                    </select>
                    @error('rent_basis') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                </div>

                @if ($rent_basis === 'per_sqft')
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Rate (laari/ft²)</label>
                            <input type="number" wire:model="rate_laari" class="h-9 w-full rounded border border-line bg-surface px-3 text-right text-sm tabular-nums text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                            @error('rate_laari') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Area (ft²)</label>
                            <input type="number" wire:model="area_sqft" class="h-9 w-full rounded border border-line bg-surface px-3 text-right text-sm tabular-nums text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                            @error('area_sqft') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @elseif ($rent_basis === 'flat')
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Flat monthly amount (MVR)</label>
                        <input type="text" wire:model="flat_amount" placeholder="0.00" class="h-9 w-full rounded border border-line bg-surface px-3 text-right text-sm tabular-nums text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                        @error('flat_amount') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                @endif

                {{-- Charge configuration --}}
                <div class="grid grid-cols-1 gap-4 rounded border border-line-2 bg-sunken p-4 sm:col-span-2 sm:grid-cols-3">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-muted sm:col-span-3">Charge configuration</p>
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Grace period (months)</label>
                        <input type="number" wire:model="grace_months" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                        @error('grace_months') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Due day of month</label>
                        <input type="number" wire:model="due_day" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                        @error('due_day') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">CSR charge</label>
                        <select wire:model.live="csr_type" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                            @foreach ($csrTypes as $c)
                                <option value="{{ $c->value }}">{{ $c->label() }}</option>
                            @endforeach
                        </select>
                        @error('csr_type') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                    </div>

                    @if ($csr_type === 'fixed_annual')
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Annual amount (MVR)</label>
                            <input type="text" wire:model="csr_amount" placeholder="0.00" class="h-9 w-full rounded border border-line bg-surface px-3 text-right text-sm tabular-nums text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                            @error('csr_amount') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                    @elseif ($csr_type === 'percent_revenue')
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Percent of revenue (%)</label>
                            <input type="text" wire:model="csr_percent" placeholder="1" class="h-9 w-full rounded border border-line bg-surface px-3 text-right text-sm tabular-nums text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                            @error('csr_percent') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Declared revenue (MVR)</label>
                            <input type="text" wire:model="csr_declared_revenue" placeholder="0.00" class="h-9 w-full rounded border border-line bg-surface px-3 text-right text-sm tabular-nums text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                            @error('csr_declared_revenue') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($csr_type !== 'none')
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">CSR billed in</label>
                            <select wire:model="csr_month" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                                <option value="">Select month…</option>
                                @for ($m = 1; $m <= 12; $m++)
                                    <option value="{{ $m }}">{{ date('F', mktime(0, 0, 0, $m, 1)) }}</option>
                                @endfor
                            </select>
                            @error('csr_month') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                    @endif
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
                    <th class="px-4 py-2 font-semibold">Agreement</th>
                    <th class="px-4 py-2 font-semibold">Property</th>
                    <th class="px-4 py-2 font-semibold">Tenant</th>
                    <th class="px-4 py-2 text-right font-semibold">Monthly rent</th>
                    <th class="px-4 py-2 font-semibold">Expiry</th>
                    <th class="px-4 py-2 font-semibold">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($leases as $lease)
                    <tr class="border-b border-line-2 last:border-0 hover:bg-hover">
                        <td class="px-4 py-3 font-medium text-ink">{{ $lease->agreement_number }}</td>
                        <td class="px-4 py-3 text-subtle">{{ $lease->property->name }}</td>
                        <td class="px-4 py-3 text-subtle">{{ $lease->tenant->name }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink">{{ $lease->monthlyRent()->format() }}</td>
                        <td class="px-4 py-3 tabular-nums text-subtle">{{ $lease->expiry_date->toDateString() }}</td>
                        <td class="px-4 py-3">
                            @php
                                $badge = match ($lease->status) {
                                    \App\Enums\LeaseStatus::Active => 'bg-success-bg text-success-fg',
                                    \App\Enums\LeaseStatus::Terminated => 'bg-danger-bg text-danger-fg',
                                    default => 'text-subtle',
                                };
                            @endphp
                            <span class="inline-flex items-center rounded-sm px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $badge }}"
                                @if ($badge === 'text-subtle') style="background:#EBECF0" @endif>
                                {{ $lease->status->label() }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="edit({{ $lease->id }})" class="rounded px-2 py-1 text-[13px] font-medium text-brand-600 hover:bg-hover">Edit</button>
                            @can('configureFineRule', $lease)
                                <button wire:click="configureFine({{ $lease->id }})" class="rounded px-2 py-1 text-[13px] font-medium text-subtle hover:bg-hover">Fine rule</button>
                            @endcan
                            @if ($lease->isActive())
                                <button wire:click="startTerminate({{ $lease->id }})" class="rounded px-2 py-1 text-[13px] font-medium text-danger-fg hover:bg-hover">Terminate</button>
                            @endif
                        </td>
                    </tr>

                    @if ($fineRuleLeaseId === $lease->id)
                        <tr class="bg-sunken">
                            <td colspan="7" class="px-4 py-4">
                                <div class="mb-3 flex items-baseline justify-between">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-subtle">Fine rule — {{ $lease->agreement_number }}</p>
                                    <p class="text-[13px] text-muted">
                                        Current:
                                        @if ($current = $lease->currentFineRule())
                                            {{ $current->summary() }} (since {{ $current->effective_from->toDateString() }})
                                        @else
                                            none — no fines accrue
                                        @endif
                                    </p>
                                </div>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                                    <div>
                                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Method</label>
                                        <select wire:model.live="fine_method" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                                            @foreach ($fineMethods as $m)
                                                <option value="{{ $m->value }}">{{ $m->label() }}</option>
                                            @endforeach
                                        </select>
                                        @error('fine_method') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                                    </div>

                                    @if ($fine_method === 'flat_per_day')
                                        <div>
                                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Amount per day (MVR)</label>
                                            <input type="text" wire:model="fine_flat_amount" placeholder="3.75" class="h-9 w-full rounded border border-line bg-surface px-3 text-right text-sm tabular-nums text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                                            @error('fine_flat_amount') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                                        </div>
                                    @elseif ($fine_method === 'percent_per_day')
                                        <div>
                                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Percent per day (%)</label>
                                            <input type="text" wire:model="fine_percent" placeholder="0.5" class="h-9 w-full rounded border border-line bg-surface px-3 text-right text-sm tabular-nums text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                                            @error('fine_percent') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Base</label>
                                            <select wire:model="fine_base" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                                                @foreach ($fineBases as $b)
                                                    <option value="{{ $b->value }}">{{ ucfirst($b->label()) }}</option>
                                                @endforeach
                                            </select>
                                            @error('fine_base') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                                        </div>
                                    @else
                                        <div>
                                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">First month (MVR)</label>
                                            <input type="text" wire:model="fine_first_month" class="h-9 w-full rounded border border-line bg-surface px-3 text-right text-sm tabular-nums text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                                            @error('fine_first_month') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Each further month (MVR)</label>
                                            <input type="text" wire:model="fine_subsequent_month" class="h-9 w-full rounded border border-line bg-surface px-3 text-right text-sm tabular-nums text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                                            @error('fine_subsequent_month') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                                        </div>
                                    @endif

                                    <div>
                                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Allowance (days)</label>
                                        <input type="number" wire:model="fine_allowance_days" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                                        @error('fine_allowance_days') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Maximum cap (MVR, optional)</label>
                                        <input type="text" wire:model="fine_cap" class="h-9 w-full rounded border border-line bg-surface px-3 text-right text-sm tabular-nums text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                                        @error('fine_cap') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Effective from</label>
                                        <input type="date" wire:model="fine_effective_from" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                                        @error('fine_effective_from') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                                    </div>

                                    <div class="flex items-end gap-2 sm:col-span-4">
                                        <button wire:click="saveFineRule" class="h-8 rounded bg-brand-500 px-4 text-sm font-medium text-white transition hover:bg-brand-600 active:bg-brand-700">Save fine rule</button>
                                        <button wire:click="cancel" class="h-8 rounded px-4 text-sm font-medium text-subtle transition hover:bg-hover">Cancel</button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif

                    @if ($terminatingId === $lease->id)
                        <tr class="bg-danger-bg/30">
                            <td colspan="7" class="px-4 py-3">
                                <div class="flex items-end gap-3">
                                    <div class="flex-1">
                                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-danger-fg">Reason for terminating {{ $lease->agreement_number }}</label>
                                        <input type="text" wire:model="termination_reason" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                                        @error('termination_reason') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                                    </div>
                                    <button wire:click="confirmTerminate" class="h-8 rounded bg-danger-fg px-4 text-sm font-medium text-white transition hover:opacity-90">Confirm termination</button>
                                    <button wire:click="cancel" class="h-8 rounded px-4 text-sm font-medium text-subtle transition hover:bg-hover">Cancel</button>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-[13px] text-muted">No leases yet — add the first one.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
