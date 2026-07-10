<div wire:keydown.escape.window="closeOverlays">
    <x-toast />

    <nav class="mb-1.5 flex items-center gap-1.5 text-12 text-muted"><span>Council</span><span>/</span><span class="text-subtle">Leases</span></nav>
    <div class="mb-4 flex items-end justify-between gap-4">
        <h1 class="text-24 font-semibold text-ink">Leases</h1>
        @can('create', \App\Models\Lease::class)
            <button wire:click="create" class="btn-primary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                Create lease
            </button>
        @endcan
    </div>

    {{-- ============ Create / edit modal (design PRD §5.8) ============ --}}
    @if ($showForm)
        <x-modal :title="$editingId ? 'Edit lease' : 'Add a lease'" close="cancel" :wide="true">
            <form wire:submit="save">
                <div class="grid grid-cols-1 gap-4 px-5 py-4 sm:grid-cols-2">
                <div>
                    <label class="fl">Agreement number</label>
                    <input type="text" wire:model="agreement_number" class="input mt-1">
                    @error('agreement_number') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="fl">Status</label>
                    <select wire:model="status" class="input mt-1">
                        <option value="draft">Draft</option>
                        <option value="active">Active</option>
                    </select>
                    @error('status') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="fl">Property</label>
                    <select wire:model="property_id" class="input mt-1">
                        <option value="">Select…</option>
                        @foreach ($properties as $property)
                            <option value="{{ $property->id }}">{{ $property->name }} ({{ $property->land_number }})</option>
                        @endforeach
                    </select>
                    @error('property_id') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="fl">Tenant</label>
                    <select wire:model="tenant_id" class="input mt-1">
                        <option value="">Select…</option>
                        @foreach ($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                    @error('tenant_id') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="fl">Agreement date</label>
                    <input type="date" wire:model="agreement_date" class="input mt-1">
                    @error('agreement_date') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="fl">Duration (years)</label>
                    <input type="number" wire:model.blur="duration_years" class="input mt-1">
                    @error('duration_years') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="fl">Lease start date</label>
                    <input type="date" wire:model.blur="start_date" class="input mt-1">
                    @error('start_date') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="fl">Rent-start date</label>
                    <input type="date" wire:model="rent_start_date" class="input mt-1">
                    @error('rent_start_date') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="fl">Expiry date <span class="normal-case text-muted">(auto from start + duration)</span></label>
                    <input type="date" wire:model="expiry_date" class="input mt-1">
                    @error('expiry_date') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="fl">Rent basis</label>
                    <select wire:model.live="rent_basis" class="input mt-1">
                        <option value="">Select…</option>
                        @foreach ($rentBases as $basis)
                            <option value="{{ $basis->value }}">{{ $basis->label() }}</option>
                        @endforeach
                    </select>
                    @error('rent_basis') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                </div>

                @if ($rent_basis === 'per_sqft')
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="fl">Rate (laari/ft²)</label>
                            <input type="number" wire:model="rate_laari" class="input mt-1 text-right tabular-nums">
                            @error('rate_laari') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="fl">Area (ft²)</label>
                            <input type="number" wire:model="area_sqft" class="input mt-1 text-right tabular-nums">
                            @error('area_sqft') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @elseif ($rent_basis === 'flat')
                    <div>
                        <label class="fl">Flat monthly amount (MVR)</label>
                        <input type="text" wire:model="flat_amount" placeholder="0.00" class="input mt-1 text-right tabular-nums">
                        @error('flat_amount') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                @endif

                {{-- Charge configuration --}}
                <div class="grid grid-cols-1 gap-4 rounded border border-line-2 bg-sunken p-4 sm:col-span-2 sm:grid-cols-3">
                    <p class="fl sm:col-span-3">Charge configuration</p>
                    <div>
                        <label class="fl">Grace period (months)</label>
                        <input type="number" wire:model="grace_months" class="input mt-1">
                        @error('grace_months') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="fl">Due day of month</label>
                        <input type="number" wire:model="due_day" class="input mt-1">
                        @error('due_day') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="fl">CSR charge</label>
                        <select wire:model.live="csr_type" class="input mt-1">
                            @foreach ($csrTypes as $c)
                                <option value="{{ $c->value }}">{{ $c->label() }}</option>
                            @endforeach
                        </select>
                        @error('csr_type') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>

                    @if ($csr_type === 'fixed_annual')
                        <div>
                            <label class="fl">Annual amount (MVR)</label>
                            <input type="text" wire:model="csr_amount" placeholder="0.00" class="input mt-1 text-right tabular-nums">
                            @error('csr_amount') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                    @elseif ($csr_type === 'percent_revenue')
                        <div>
                            <label class="fl">Percent of revenue (%)</label>
                            <input type="text" wire:model="csr_percent" placeholder="1" class="input mt-1 text-right tabular-nums">
                            @error('csr_percent') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="fl">Declared revenue (MVR)</label>
                            <input type="text" wire:model="csr_declared_revenue" placeholder="0.00" class="input mt-1 text-right tabular-nums">
                            @error('csr_declared_revenue') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($csr_type !== 'none')
                        <div>
                            <label class="fl">CSR billed in</label>
                            <select wire:model="csr_month" class="input mt-1">
                                <option value="">Select month…</option>
                                @for ($m = 1; $m <= 12; $m++)
                                    <option value="{{ $m }}">{{ date('F', mktime(0, 0, 0, $m, 1)) }}</option>
                                @endfor
                            </select>
                            @error('csr_month') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>

                </div>
                <div class="flex items-center justify-end gap-2 rounded-b-lg border-t border-line-2 bg-sunken px-5 py-3.5">
                    <button type="button" wire:click="cancel" class="btn-subtle">Cancel</button>
                    <button type="submit" class="btn-primary">{{ $editingId ? 'Save changes' : 'Create lease' }}</button>
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
            <input type="text" wire:model.live.debounce.300ms="q" placeholder="Filter leases"
                class="h-8 w-56 rounded border border-line bg-surface pl-8 pr-3 text-13 placeholder:text-muted focus:border-brand-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-300">
        </label>

        <select wire:model.live="statusFilter" class="chip {{ $statusFilter !== '' ? 'border-brand-500 text-brand-600' : '' }}">
            <option value="">Status</option>
            @foreach ($leaseStatuses as $statusOption)
                <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
            @endforeach
        </select>

        <select wire:model.live="tenantTypeFilter" class="chip {{ $tenantTypeFilter !== '' ? 'border-brand-500 text-brand-600' : '' }}">
            <option value="">Tenant type</option>
            @foreach ($tenantTypes as $typeOption)
                <option value="{{ $typeOption->value }}">{{ $typeOption->label() }}</option>
            @endforeach
        </select>

        <select wire:model.live="propertyTypeFilter" class="chip {{ $propertyTypeFilter !== '' ? 'border-brand-500 text-brand-600' : '' }}">
            <option value="">Property type</option>
            @foreach ($usageTypes as $usageOption)
                <option value="{{ $usageOption->value }}">{{ $usageOption->label() }}</option>
            @endforeach
        </select>

        @if ($q !== '' || $statusFilter !== '' || $tenantTypeFilter !== '' || $propertyTypeFilter !== '')
            <button wire:click="clearFilters" class="btn-subtle h-8 px-2 text-12">Clear filters</button>
        @endif

        <div class="ml-auto text-12 text-muted">{{ $leases->total() }} lease{{ $leases->total() === 1 ? '' : 's' }}</div>
    </div>

    <div class="mb-2 flex items-center gap-1 border-b border-line-2">
        @foreach (['all' => 'All', 'active' => 'Active', 'overdue' => 'Overdue', 'expiring' => 'Expiring soon'] as $key => $label)
            <button wire:click="$set('tab', '{{ $key }}')" class="tab {{ $tab === $key ? 'tab-active' : '' }}">{{ $label }}</button>
        @endforeach
    </div>

    {{-- ============ Issue-list table (design PRD §5.4, §6.2) ============ --}}
    <div class="overflow-hidden rounded-md border border-line bg-surface shadow-card">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[820px] text-13">
                <thead>
                    <tr class="border-b border-line bg-sunken text-11 uppercase tracking-wide text-muted">
                        <th class="th text-left">Property</th>
                        <th class="th text-left">Tenant</th>
                        <th class="th text-left">Type</th>
                        <th class="th text-left">Status</th>
                        <th class="th text-right">Monthly rent</th>
                        <th class="th text-left">Next due</th>
                        <th class="th w-10"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-2">
                    @forelse ($leases as $lease)
                        @php
                            $avaColors = ['bg-brand-500', 'bg-discovery-fg', 'bg-success-fg', 'bg-warning-fg', 'bg-brand-600', 'bg-danger-fg'];
                            $ava = $avaColors[$lease->tenant->id % count($avaColors)];
                            $initials = collect(explode(' ', $lease->tenant->name))->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');
                            $expiresInDays = (int) today()->diffInDays($lease->expiry_date, false);

                            if ($lease->overdue_invoices_count > 0) {
                                $days = (int) \Illuminate\Support\Carbon::parse($lease->oldest_overdue_due)->diffInDays(today());
                                [$lozClass, $lozText] = ['loz-danger', "Overdue {$days}d"];
                            } elseif ($lease->status === \App\Enums\LeaseStatus::Active && $expiresInDays >= 0 && $expiresInDays <= 90) {
                                [$lozClass, $lozText] = ['loz-warning', "Expires {$expiresInDays}d"];
                            } else {
                                [$lozClass, $lozText] = match ($lease->status) {
                                    \App\Enums\LeaseStatus::Active => ['loz-success', 'Active'],
                                    \App\Enums\LeaseStatus::Terminated => ['loz-danger', 'Terminated'],
                                    \App\Enums\LeaseStatus::Expired => ['loz-neutral', 'Expired'],
                                    default => ['loz-neutral', 'Draft'],
                                };
                            }
                        @endphp
                        <tr wire:click="selectLease({{ $lease->id }})" class="cursor-pointer hover:bg-hover {{ $selectedId === $lease->id ? 'bg-selected' : '' }}">
                            <td class="td">
                                <p class="font-medium text-ink">{{ $lease->property->name }}</p>
                                <p class="text-12 text-muted">{{ $lease->agreement_number }} · {{ number_format($lease->property->size_sqft) }} ft²</p>
                            </td>
                            <td class="td">
                                <span class="flex items-center gap-2"><span class="ava {{ $ava }}">{{ $initials }}</span>{{ $lease->tenant->name }}</span>
                            </td>
                            <td class="td">
                                <span class="loz {{ $lease->tenant->isOrganisation() ? 'loz-discovery' : 'loz-info' }}">{{ $lease->tenant->type->label() }}</span>
                            </td>
                            <td class="td"><span class="loz {{ $lozClass }}">{{ $lozText }}</span></td>
                            <td class="td text-right tabular-nums">{{ $lease->monthlyRent()->format() }}</td>
                            <td class="td text-subtle">
                                {{ $lease->next_due ? \Illuminate\Support\Carbon::parse($lease->next_due)->format('j M Y') : '—' }}
                            </td>
                            <td class="td text-right text-muted">›</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-13 text-muted">
                            @if ($q !== '' || $tab !== 'all' || $statusFilter !== '' || $tenantTypeFilter !== '' || $propertyTypeFilter !== '')
                                No leases match this view — clear the filters or switch tabs.
                            @else
                                No leases yet — create the first one.
                            @endif
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :paginator="$leases" />
    </div>

    {{-- ============ Slide-over: lease detail (design PRD §5.7, §6.3) ============ --}}
    @if ($detail !== null)
        @php $lease = $detail['lease']; @endphp
        <div wire:click="closeLease" class="overlay-enter fixed inset-0 z-40 bg-ink/30" aria-hidden="true"></div>
        <div class="slideover-enter fixed bottom-0 right-0 top-0 z-50 flex w-full max-w-[560px] flex-col bg-surface shadow-overlay" role="dialog" aria-modal="true">
            {{-- header --}}
            <div class="flex items-start gap-3 border-b border-line-2 px-5 py-4">
                <div class="min-w-0 flex-1">
                    <div class="mb-1 flex items-center gap-2 text-12 text-muted">
                        <span>{{ $lease->agreement_number }}</span><span>·</span><span>{{ $lease->property->land_number }}</span>
                    </div>
                    <h2 class="truncate text-20 font-semibold text-ink">{{ $lease->property->name }}</h2>
                </div>
                <button wire:click="closeLease" class="icon-btn" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            {{-- action bar --}}
            <div class="flex items-center gap-2 border-b border-line-2 bg-sunken px-5 py-2.5">
                @can('create', \App\Models\Payment::class)
                    @if ($detail['unpaid']->isNotEmpty())
                        <button wire:click="startPayment({{ $detail['unpaid']->first()->id }})" class="btn-primary">Record payment</button>
                    @endif
                @endcan
                @can(\App\Enums\Permission::IssueInvoices->value)
                    @if ($detail['unpaid']->isNotEmpty())
                        <button wire:click="sendReminder({{ $detail['unpaid']->first()->id }})" class="btn-subtle">Send reminder</button>
                    @endif
                    <a href="{{ route('invoices.index', ['createFor' => $lease->id]) }}" class="btn-subtle">Create invoice</a>
                @endcan
                <span class="ml-auto flex items-center gap-2">
                    @can('configureFineRule', $lease)
                        <button wire:click="configureFine({{ $lease->id }})" class="btn-subtle">Fine rule</button>
                    @endcan
                    @if ($lease->isActive())
                        @can('terminate', $lease)
                            <button wire:click="startTerminate({{ $lease->id }})" class="btn-subtle text-danger-fg">Terminate</button>
                        @endcan
                    @endif
                    @can('update', $lease)
                        <button wire:click="edit({{ $lease->id }})" class="btn-subtle">Edit</button>
                    @endcan
                </span>
            </div>

            {{-- body --}}
            <div class="flex-1 overflow-y-auto pb-6">
                {{-- balance banner --}}
                @if ($detail['outstanding']->isPositive())
                    <div class="mx-5 mt-4 flex items-center justify-between rounded-md border px-4 py-3 {{ $detail['overdue_days'] > 0 ? 'border-danger-bg bg-danger-bg/40' : 'border-warning-bg bg-warning-bg/40' }}">
                        <div>
                            <p class="text-12 font-semibold uppercase tracking-wide {{ $detail['overdue_days'] > 0 ? 'text-danger-fg' : 'text-warning-fg' }}">Amount due</p>
                            <p class="text-24 font-semibold tabular-nums {{ $detail['overdue_days'] > 0 ? 'text-danger-fg' : 'text-warning-fg' }}">{{ $detail['outstanding']->format() }}</p>
                        </div>
                        @if ($detail['overdue_days'] > 0)
                            <span class="loz loz-danger">Overdue {{ $detail['overdue_days'] }} days</span>
                        @else
                            <span class="loz loz-warning">Awaiting payment</span>
                        @endif
                    </div>
                @else
                    <div class="mx-5 mt-4 flex items-center justify-between rounded-md border border-success-bg bg-success-bg/40 px-4 py-3">
                        <div>
                            <p class="text-12 font-semibold uppercase tracking-wide text-success-fg">Amount due</p>
                            <p class="text-24 font-semibold tabular-nums text-success-fg">MVR 0.00</p>
                        </div>
                        <span class="loz loz-success">All settled</span>
                    </div>
                @endif

                {{-- terminate confirm --}}
                @if ($terminatingId === $lease->id)
                    <div class="mx-5 mt-4 rounded-md border border-danger-bg bg-danger-bg/30 p-4">
                        <label class="fl text-danger-fg">Reason for terminating {{ $lease->agreement_number }}</label>
                        <input type="text" wire:model="termination_reason" class="input mt-1.5">
                        @error('termination_reason') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                        <div class="mt-3 flex gap-2">
                            <button wire:click="confirmTerminate" class="btn-danger">Confirm termination</button>
                            <button wire:click="$set('terminatingId', null)" class="btn-subtle">Cancel</button>
                        </div>
                    </div>
                @endif

                {{-- fields grid --}}
                <div class="grid grid-cols-2 gap-x-6 gap-y-4 px-5 py-4">
                    <div>
                        <p class="fl">Status</p>
                        <span class="loz mt-1 {{ match ($lease->status) {
                            \App\Enums\LeaseStatus::Active => 'loz-success',
                            \App\Enums\LeaseStatus::Terminated => 'loz-danger',
                            default => 'loz-neutral',
                        } }}">{{ $lease->status->label() }}</span>
                    </div>
                    <div><p class="fl">Tenant</p><p class="fv">{{ $lease->tenant->name }}</p></div>
                    <div><p class="fl">Tenant type</p><p class="fv">{{ $lease->tenant->type->label() }}{{ $lease->tenant->registryNumber() ? ' · '.$lease->tenant->registryNumber() : '' }}</p></div>
                    <div><p class="fl">Property</p><p class="fv">{{ $lease->property->usage_type->label() }} · {{ number_format($lease->property->size_sqft) }} ft²</p></div>
                    <div>
                        <p class="fl">Rent basis</p>
                        <p class="fv">
                            @if ($lease->rent_basis === \App\Enums\RentBasis::PerSquareFoot)
                                {{ $lease->rate_laari }} laari/ft² × {{ number_format((int) $lease->area_sqft) }} ft² · {{ $lease->monthlyRent()->format() }}/mo
                            @else
                                Flat · {{ $lease->monthlyRent()->format() }}/mo
                            @endif
                        </p>
                    </div>
                    <div><p class="fl">Due day</p><p class="fv">{{ $lease->due_day }}th of each month</p></div>
                    <div><p class="fl">Lease start</p><p class="fv">{{ $lease->start_date->format('j M Y') }}</p></div>
                    <div><p class="fl">Expiry</p><p class="fv">{{ $lease->expiry_date->format('j M Y') }}</p></div>
                    @if ($lease->grace_months > 0)
                        <div><p class="fl">Grace period</p><p class="fv">First {{ $lease->grace_months }} month(s) free</p></div>
                    @endif
                    @if ($lease->hasCsr())
                        <div><p class="fl">CSR charge</p><p class="fv">{{ $lease->csrAnnualAmount()->format() }}/year ({{ $lease->csr_type->label() }})</p></div>
                    @endif
                    @if ($lease->status === \App\Enums\LeaseStatus::Terminated)
                        <div class="col-span-2"><p class="fl">Termination</p><p class="fv">{{ $lease->terminated_on?->format('j M Y') }} — {{ $lease->termination_reason }}</p></div>
                    @endif
                </div>

                {{-- fine rule card (show the maths — design PRD goal 3) --}}
                <div class="mx-5 mb-4 rounded-md border border-line bg-surface">
                    <div class="flex h-10 items-center justify-between border-b border-line-2 px-4">
                        <p class="text-13 font-semibold text-ink">Fine rule</p>
                        @if ($detail['rule'])
                            <span class="loz loz-info">{{ $detail['rule']->method->label() }}</span>
                        @else
                            <span class="loz loz-neutral">None</span>
                        @endif
                    </div>
                    <div class="space-y-1.5 px-4 py-3 text-13 text-subtle">
                        @if ($detail['rule'])
                            <div class="flex justify-between"><span>Method</span><span class="text-ink">{{ $detail['rule']->summary() }}</span></div>
                            <div class="flex justify-between"><span>Base</span><span class="text-ink">{{ ucfirst($detail['rule']->base->label()) }}</span></div>
                            <div class="flex justify-between"><span>Allowance</span><span class="text-ink">{{ $detail['rule']->allowance_days }} days</span></div>
                            @if ($detail['rule']->cap_laari !== null)
                                <div class="flex justify-between"><span>Maximum cap</span><span class="text-ink">{{ \App\Support\Money::fromLaari($detail['rule']->cap_laari)->format() }}</span></div>
                            @endif
                            <div class="flex justify-between"><span>Effective from</span><span class="text-ink">{{ $detail['rule']->effective_from->format('j M Y') }}</span></div>
                            @if ($detail['overdue_fine'] !== null && $detail['overdue_fine']->isPositive())
                                <div class="mt-2 flex justify-between border-t border-line-2 pt-2">
                                    <span>Accrued fine ({{ $detail['overdue_days'] }} days overdue)</span>
                                    <span class="font-semibold tabular-nums text-danger-fg">{{ $detail['overdue_fine']->format() }}</span>
                                </div>
                            @endif
                        @else
                            <p>No fine rule configured — no fines accrue on this lease.</p>
                        @endif
                    </div>
                </div>

                {{-- fine rule form --}}
                @if ($fineRuleLeaseId === $lease->id)
                    <div class="mx-5 mb-4 rounded-md border border-line bg-sunken p-4">
                        <p class="fl mb-3">Change fine rule</p>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="fl">Method</label>
                                <select wire:model.live="fine_method" class="input mt-1">
                                    @foreach ($fineMethods as $m)
                                        <option value="{{ $m->value }}">{{ $m->label() }}</option>
                                    @endforeach
                                </select>
                                @error('fine_method') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                            </div>

                            @if ($fine_method === 'flat_per_day')
                                <div>
                                    <label class="fl">Amount per day (MVR)</label>
                                    <input type="text" wire:model="fine_flat_amount" placeholder="3.75" class="input mt-1 text-right tabular-nums">
                                    @error('fine_flat_amount') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                                </div>
                            @elseif ($fine_method === 'percent_per_day')
                                <div>
                                    <label class="fl">Percent per day (%)</label>
                                    <input type="text" wire:model="fine_percent" placeholder="0.5" class="input mt-1 text-right tabular-nums">
                                    @error('fine_percent') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="fl">Base</label>
                                    <select wire:model="fine_base" class="input mt-1">
                                        @foreach ($fineBases as $b)
                                            <option value="{{ $b->value }}">{{ ucfirst($b->label()) }}</option>
                                        @endforeach
                                    </select>
                                    @error('fine_base') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                                </div>
                            @else
                                <div>
                                    <label class="fl">First month (MVR)</label>
                                    <input type="text" wire:model="fine_first_month" class="input mt-1 text-right tabular-nums">
                                    @error('fine_first_month') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="fl">Each further month (MVR)</label>
                                    <input type="text" wire:model="fine_subsequent_month" class="input mt-1 text-right tabular-nums">
                                    @error('fine_subsequent_month') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                                </div>
                            @endif

                            <div>
                                <label class="fl">Allowance (days)</label>
                                <input type="number" wire:model="fine_allowance_days" class="input mt-1">
                                @error('fine_allowance_days') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="fl">Maximum cap (MVR, optional)</label>
                                <input type="text" wire:model="fine_cap" class="input mt-1 text-right tabular-nums">
                                @error('fine_cap') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="fl">Effective from</label>
                                <input type="date" wire:model="fine_effective_from" class="input mt-1">
                                @error('fine_effective_from') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <button wire:click="saveFineRule" class="btn-primary">Save fine rule</button>
                            <button wire:click="$set('fineRuleLeaseId', null)" class="btn-subtle">Cancel</button>
                        </div>
                    </div>
                @endif

                {{-- recent invoices --}}
                <div class="px-5 pb-2">
                    <p class="mb-2 text-13 font-semibold text-ink">Recent invoices</p>
                    @if ($detail['recent_invoices']->isEmpty())
                        <p class="text-13 text-muted">No invoices yet for this lease.</p>
                    @else
                        <div class="overflow-hidden rounded-md border border-line">
                            <table class="w-full text-13">
                                <tbody class="divide-y divide-line-2">
                                    @foreach ($detail['recent_invoices'] as $invoice)
                                        <tr>
                                            <td class="px-3 py-2">
                                                <span class="font-medium text-ink">{{ $invoice->periodLabel() }}</span>
                                                <span class="text-muted"> · {{ $invoice->number }}</span>
                                            </td>
                                            <td class="px-3 py-2 text-right tabular-nums">{{ $invoice->total()->format() }}</td>
                                            <td class="px-3 py-2 text-right">
                                                <span class="loz {{ match ($invoice->status) {
                                                    \App\Enums\InvoiceStatus::Paid => 'loz-success',
                                                    \App\Enums\InvoiceStatus::PartlyPaid => 'loz-warning',
                                                    \App\Enums\InvoiceStatus::Overdue => 'loz-danger',
                                                    default => 'loz-info',
                                                } }}">{{ $invoice->status->label() }}</span>
                                            </td>
                                            <td class="w-12 px-3 py-2 text-right">
                                                @can('view reports')
                                                    <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" class="text-12 font-medium text-brand-600 hover:underline">PDF</a>
                                                @endcan
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                {{-- activity --}}
                <div class="px-5 py-4">
                    <p class="mb-3 text-13 font-semibold text-ink">Activity</p>
                    @if ($detail['activity']->isEmpty())
                        <p class="text-13 text-muted">No recorded activity.</p>
                    @else
                        <ol class="space-y-3 text-13">
                            @foreach ($detail['activity'] as $entry)
                                <li class="flex gap-3">
                                    <span class="ava shrink-0 {{ $entry->causer ? 'bg-brand-500' : 'bg-success-fg' }}">
                                        {{ $entry->causer ? collect(explode(' ', $entry->causer->name))->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('') : 'SY' }}
                                    </span>
                                    <div>
                                        <p><span class="font-medium">{{ $entry->causer?->name ?? 'System' }}</span> <span class="text-muted">{{ $entry->description }} {{ $lease->agreement_number }}</span></p>
                                        <p class="text-12 text-muted">{{ $entry->created_at->format('j M Y · H:i') }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- ============ Modal: record payment (design PRD §5.8) ============ --}}
    @if ($paying !== null && $detail !== null)
        <div wire:click.self="cancelPayment" class="overlay-enter fixed inset-0 z-[60] grid place-items-start justify-center overflow-y-auto bg-ink/40 p-4 sm:p-8">
            <div class="mt-10 w-full max-w-[480px] rounded-lg bg-surface shadow-overlay" role="dialog" aria-modal="true">
                <div class="flex items-center justify-between border-b border-line-2 px-5 py-4">
                    <h3 class="text-16 font-semibold text-ink">Record payment</h3>
                    <button wire:click="cancelPayment" class="icon-btn" aria-label="Close">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="space-y-4 px-5 py-4">
                    <div>
                        <label class="fl-req">Invoice</label>
                        <select wire:model.live="payingInvoiceId" class="input mt-1">
                            @foreach ($detail['unpaid'] as $unpaidInvoice)
                                <option value="{{ $unpaidInvoice->id }}">
                                    {{ $unpaidInvoice->number }} — {{ $unpaidInvoice->periodLabel() }} ({{ $unpaidInvoice->outstandingTotal()->format() }} due)
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="fl-req">Amount received (MVR)</label>
                        <input type="text" wire:model.live="pay_amount" placeholder="0.00" class="input mt-1 text-right tabular-nums">
                        @error('pay_amount') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="fl-req">Payment date</label>
                            <input type="date" wire:model.live="pay_date" class="input mt-1">
                            @error('pay_date') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="fl-req">Method</label>
                            <select wire:model="pay_method" class="input mt-1">
                                @foreach ($methods as $method)
                                    <option value="{{ $method->value }}">{{ $method->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="fl">Reference</label>
                        <input type="text" wire:model="pay_reference" placeholder="e.g. BML txn 88421" class="input mt-1">
                        @error('pay_reference') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>

                    {{-- computed breakdown --}}
                    <div class="space-y-1.5 rounded-md border border-line-2 bg-sunken p-3 text-13">
                        <div class="flex justify-between"><span class="text-subtle">Rent &amp; charges outstanding</span><span class="tabular-nums">{{ $paying['outstanding_principal']->format() }}</span></div>
                        <div class="flex justify-between"><span class="text-subtle">Fine as of payment date</span><span class="tabular-nums">{{ $paying['outstanding_fine']->format() }}</span></div>
                        <div class="mt-1.5 flex justify-between border-t border-line pt-1.5 font-semibold"><span>Total due</span><span class="tabular-nums">{{ $paying['outstanding_total']->format() }}</span></div>
                        @if ($paying['allocation'] !== null)
                            <div class="flex justify-between pt-1"><span class="text-subtle">→ allocates to rent</span><span class="tabular-nums">{{ $paying['allocation']['principal']->format() }}</span></div>
                            <div class="flex justify-between"><span class="text-subtle">→ allocates to fine</span><span class="tabular-nums">{{ $paying['allocation']['fine']->format() }}</span></div>
                            @if ($paying['allocation']['excess'] > 0)
                                <p class="pt-1 text-13 text-danger-fg">Exceeds the outstanding balance.</p>
                            @endif
                        @endif
                        <p class="pt-1 text-11 text-muted">Allocation: rent first, then fine. The fine is computed on the payment date.</p>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2 rounded-b-lg border-t border-line-2 bg-sunken px-5 py-3.5">
                    <button wire:click="cancelPayment" class="btn-subtle">Cancel</button>
                    <button wire:click="confirmPayment" class="btn-primary">Record payment</button>
                </div>
            </div>
        </div>
    @endif
</div>
