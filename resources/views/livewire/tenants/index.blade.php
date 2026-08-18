<div wire:keydown.escape.window="closeOverlays">
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
                        <x-select wire:model.live="type" class="mt-1"
                            :options="collect($types)->mapWithKeys(fn ($t) => [$t->value => $t->label()])" />
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
                <div class="flex items-center justify-end gap-2 rounded-b-xl border-t border-line-2 bg-sunken px-5 py-3.5">
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
                class="h-8 w-56 rounded border border-line bg-surface pl-8 pr-3 text-13 shadow-xs transition-[border-color,box-shadow] duration-100 placeholder:text-muted focus:border-brand-500 focus:ring-2 focus:ring-brand-300/40 focus-visible:outline-none">
        </label>

        <x-select wire:model.live="typeFilter" chip
            :options="collect(['' => 'Tenant type'])->merge(collect($types)->mapWithKeys(fn ($t) => [$t->value => $t->label()]))" />

        @if ($q !== '' || $typeFilter !== '')
            <button wire:click="clearFilters" class="btn-subtle h-8 px-2 text-12">Clear filters</button>
        @endif

        <div class="ml-auto text-12 text-muted">{{ $tenants->total() }} tenant{{ $tenants->total() === 1 ? '' : 's' }}</div>
    </div>

    <div class="overflow-hidden rounded-md border border-line bg-surface shadow-card">
        <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-line">
                    <th class="th text-left">Name</th>
                    <th class="th text-left">Type</th>
                    <th class="th text-left">Registry no.</th>
                    <th class="th text-left">Mobile</th>
                    <th class="th text-right">Leases</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tenants as $tenant)
                    <tr wire:click="selectTenant({{ $tenant->id }})" class="cursor-pointer border-b border-line-2 last:border-0 hover:bg-hover {{ $selectedId === $tenant->id ? 'bg-selected' : '' }}">
                        <td class="px-4 py-3">
                            <span class="flex items-center gap-2">
                                <span class="ava {{ ['bg-brand-500', 'bg-discovery-fg', 'bg-success-fg', 'bg-warning-fg', 'bg-brand-600', 'bg-danger-fg'][$tenant->id % 6] }}">
                                    {{ collect(explode(' ', $tenant->name))->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('') }}
                                </span>
                                <span class="font-medium text-ink">{{ $tenant->name }}</span>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="loz {{ $tenant->isOrganisation() ? 'loz-discovery' : 'loz-info' }}">{{ $tenant->type->label() }}</span>
                        </td>
                        <td class="px-4 py-3 text-subtle">{{ $tenant->registryNumber() }}</td>
                        <td class="px-4 py-3 text-subtle">{{ $tenant->mobile }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink">{{ $tenant->leases_count }}</td>
                        <td class="px-4 py-3 text-right text-muted">›</td>
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

    {{-- ============ Slide-over: tenant detail with drill-down (design PRD §6) ============ --}}
    @if ($detail !== null)
        @php $t = $detail['tenant']; @endphp
        <div wire:click="closeTenant" class="overlay-enter fixed inset-0 z-40 bg-navy/40 backdrop-blur-[2px]" aria-hidden="true"></div>
        <div class="slideover-enter fixed bottom-0 right-0 top-0 z-50 flex w-full max-w-[560px] flex-col bg-surface shadow-overlay" role="dialog" aria-modal="true">
            {{-- header --}}
            <div class="flex items-start gap-3 border-b border-line-2 px-5 py-4">
                <span class="ava mt-1 h-8 w-8 text-13 {{ ['bg-brand-500', 'bg-discovery-fg', 'bg-success-fg', 'bg-warning-fg', 'bg-brand-600', 'bg-danger-fg'][$t->id % 6] }}">
                    {{ collect(explode(' ', $t->name))->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('') }}
                </span>
                <div class="min-w-0 flex-1">
                    <div class="mb-0.5 flex items-center gap-2">
                        <span class="loz {{ $t->isOrganisation() ? 'loz-discovery' : 'loz-info' }}">{{ $t->type->label() }}</span>
                        @if ($t->registryNumber())
                            <span class="text-12 text-muted">{{ $t->registryNumber() }}</span>
                        @endif
                    </div>
                    <h2 class="truncate text-24 font-bold text-ink">{{ $t->name }}</h2>
                </div>
                <button wire:click="closeTenant" class="icon-btn" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            {{-- action bar --}}
            <div class="flex flex-wrap items-center gap-2 border-b border-line-2 px-5 py-3">
                @can('create', \App\Models\Payment::class)
                    @if ($detail['balance']->isPositive())
                        {{-- One handover of money, spread across what they owe (FR-PAY-01). --}}
                        <button wire:click="startCollect" class="btn-primary">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                            Record payment
                        </button>
                    @endif
                @endcan
                @can('view reports')
                    <a href="{{ route('tenants.statement', $t) }}" class="btn-secondary">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h6"/></svg>
                        Statement
                    </a>
                @endcan
                <span class="ml-auto">
                    @can('update', $t)
                        <button wire:click="edit({{ $t->id }})" class="btn-subtle">Edit</button>
                    @endcan
                </span>
            </div>

            {{-- body --}}
            <div class="flex-1 overflow-y-auto bg-sunken pb-6">
                {{-- consolidated balance (FR-TEN-05) --}}
                @if ($detail['balance']->isPositive())
                    <div class="mx-5 mt-4 flex items-center justify-between rounded-md bg-danger-bg px-5 py-4">
                        <div>
                            <p class="text-12 font-bold uppercase tracking-[0.08em] text-danger-fg">Balance due · all leases</p>
                            <p class="mt-1 font-display text-[28px] font-extrabold leading-8 tracking-[-0.02em] tabular-nums text-danger-fg">{{ $detail['balance']->format() }}</p>
                        </div>
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-danger-fg"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01"/></svg>
                    </div>
                @else
                    <div class="mx-5 mt-4 flex items-center justify-between rounded-md bg-success-bg px-5 py-4">
                        <div>
                            <p class="text-12 font-bold uppercase tracking-[0.08em] text-success-fg">Balance due · all settled</p>
                            <p class="mt-1 font-display text-[28px] font-extrabold leading-8 tracking-[-0.02em] tabular-nums text-success-fg">MVR 0.00</p>
                        </div>
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-success-fg"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                    </div>
                @endif

                {{-- Collect payment: one amount, spread oldest-first (FR-PAY-01). --}}
                @if ($collecting && $collectPreview !== null)
                    <div class="mx-5 mt-4">
                        <x-collect-payment :preview="$collectPreview" :methods="$methods" />
                    </div>
                @endif

                {{-- contact details --}}
                <div class="mx-5 mt-4 mb-4 divide-y divide-line-2 rounded-md border border-line bg-surface shadow-card">
                    <div class="flex items-center gap-4 px-4 py-3">
                        <p class="w-32 shrink-0 text-13 text-muted">Mobile</p>
                        <p class="min-w-0 flex-1 text-13 text-ink">{{ $t->mobile ?: '—' }}</p>
                    </div>
                    <div class="flex items-center gap-4 px-4 py-3">
                        <p class="w-32 shrink-0 text-13 text-muted">Email</p>
                        <p class="min-w-0 flex-1 text-13 text-ink">{{ $t->email ?: '—' }}</p>
                    </div>
                    <div class="flex items-center gap-4 px-4 py-3">
                        <p class="w-32 shrink-0 text-13 text-muted">SMS reminders</p>
                        <span class="loz {{ $t->canReceiveSms() ? 'loz-success' : 'loz-warning' }}">{{ $t->canReceiveSms() ? 'Enabled' : ($t->sms_opt_out ? 'Opted out' : 'No mobile') }}</span>
                    </div>
                    @if ($t->isOrganisation() && $t->contact_person)
                        <div class="flex items-center gap-4 px-4 py-3">
                            <p class="w-32 shrink-0 text-13 text-muted">Contact person</p>
                            <p class="min-w-0 flex-1 text-13 text-ink">{{ $t->contact_person }}</p>
                        </div>
                    @endif
                    @if ($t->postal_address)
                        <div class="flex items-center gap-4 px-4 py-3">
                            <p class="w-32 shrink-0 text-13 text-muted">Postal address</p>
                            <p class="min-w-0 flex-1 text-13 text-ink">{{ $t->postal_address }}</p>
                        </div>
                    @endif
                </div>

                {{-- leases → invoices → payments drill-down --}}
                <div class="mx-5 mb-4 rounded-md border border-line bg-surface p-4 shadow-card">
                    <p class="mb-2.5 font-display text-16 font-bold tracking-[-0.01em] text-ink">Leases ({{ $detail['leases']->count() }})</p>
                    @if ($detail['leases']->isEmpty())
                        <p class="text-13 text-muted">No leases yet for this tenant.</p>
                    @else
                        <div class="overflow-hidden rounded border border-line-2">
                            @foreach ($detail['leases'] as $lease)
                                @php $leaseOutstanding = max((int) $lease->invoiced_laari - (int) $lease->paid_laari, 0); @endphp
                                <button wire:click="toggleLease({{ $lease->id }})"
                                    class="flex w-full items-center gap-2 border-b border-line-2 px-3 py-2.5 text-left text-13 last:border-0 hover:bg-hover {{ $expandedLeaseId === $lease->id ? 'bg-sunken' : '' }}">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        class="shrink-0 text-muted transition-transform {{ $expandedLeaseId === $lease->id ? 'rotate-90' : '' }}"><path d="m9 18 6-6-6-6"/></svg>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-medium text-ink">{{ $lease->property->name }}</span>
                                        <span class="block text-12 text-muted">{{ $lease->agreement_number }} · {{ $lease->monthlyRent()->format() }}/mo</span>
                                    </span>
                                    <span class="loz {{ match ($lease->status) {
                                        \App\Enums\LeaseStatus::Active => 'loz-success',
                                        \App\Enums\LeaseStatus::Terminated => 'loz-danger',
                                        default => 'loz-neutral',
                                    } }}">{{ $lease->status->label() }}</span>
                                    <span class="tabular-nums {{ $leaseOutstanding > 0 ? 'font-medium text-danger-fg' : 'text-subtle' }}">
                                        {{ \App\Support\Money::fromLaari($leaseOutstanding)->format() }}
                                    </span>
                                </button>

                                @if ($expandedLeaseId === $lease->id)
                                    <div class="border-b border-line-2 bg-sunken px-3 py-2 last:border-0">
                                        @if ($detail['invoices']->isEmpty())
                                            <p class="px-6 py-2 text-12 text-muted">No invoices yet on this lease.</p>
                                        @else
                                            @foreach ($detail['invoices'] as $invoice)
                                                <button wire:click="toggleInvoice({{ $invoice->id }})"
                                                    class="flex w-full items-center gap-2 rounded px-2 py-2 pl-6 text-left text-13 hover:bg-hover {{ $expandedInvoiceId === $invoice->id ? 'bg-hover' : '' }}">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                        class="shrink-0 text-muted transition-transform {{ $expandedInvoiceId === $invoice->id ? 'rotate-90' : '' }}"><path d="m9 18 6-6-6-6"/></svg>
                                                    <span class="min-w-0 flex-1">
                                                        <span class="font-medium text-ink">{{ $invoice->number }}</span>
                                                        <span class="text-12 text-muted"> · {{ $invoice->periodLabel() }}</span>
                                                    </span>
                                                    <span class="loz {{ match ($invoice->status) {
                                                        \App\Enums\InvoiceStatus::Paid => 'loz-success',
                                                        \App\Enums\InvoiceStatus::PartlyPaid => 'loz-warning',
                                                        \App\Enums\InvoiceStatus::Overdue => 'loz-danger',
                                                        \App\Enums\InvoiceStatus::Cancelled => 'loz-neutral',
                                                        default => 'loz-info',
                                                    } }}">{{ $invoice->status->label() }}</span>
                                                    <span class="tabular-nums text-ink">{{ $invoice->total()->format() }}</span>
                                                    @can('view reports')
                                                        <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" wire:click.stop
                                                            class="icon-btn h-6 w-6" title="Invoice PDF" aria-label="Invoice PDF">
                                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                                                        </a>
                                                    @endcan
                                                </button>

                                                @if ($expandedInvoiceId === $invoice->id)
                                                    <div class="mb-1 ml-11 mr-2 rounded border border-line-2 bg-surface">
                                                        @forelse ($detail['payments'] as $payment)
                                                            <div class="flex items-center gap-2 border-b border-line-2 px-3 py-2 text-12 last:border-0">
                                                                <span class="min-w-0 flex-1">
                                                                    @if ($payment->isReversal())
                                                                        <span class="font-medium text-danger-fg">Reversal of {{ $payment->reference }}</span>
                                                                        <span class="block text-muted">{{ $payment->reversal_reason }}</span>
                                                                    @else
                                                                        <span class="font-medium text-ink">Receipt {{ $payment->receipt_number }}</span>
                                                                        <span class="block text-muted">{{ $payment->payment_date->format('j M Y') }} · {{ $payment->method->label() }} · rent {{ $payment->principalAllocated()->format() }} · fine {{ $payment->fineAllocated()->format() }}</span>
                                                                    @endif
                                                                </span>
                                                                <span class="tabular-nums {{ $payment->amount_laari < 0 ? 'text-danger-fg' : 'text-ink' }}">{{ $payment->amount()->format() }}</span>
                                                                @if (! $payment->isReversal())
                                                                    @can('view reports')
                                                                        <a href="{{ route('payments.receipt', $payment) }}" target="_blank" class="icon-btn h-6 w-6" title="Receipt PDF" aria-label="Receipt PDF">
                                                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M8 7h8M8 11h8M8 15h5"/></svg>
                                                                        </a>
                                                                    @endcan
                                                                @endif
                                                            </div>
                                                        @empty
                                                            <p class="px-3 py-2 text-12 text-muted">No payments recorded on this invoice.</p>
                                                        @endforelse
                                                    </div>
                                                @endif
                                            @endforeach
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- recent messages (design PRD §6 "message history") --}}
                <div class="mx-5 rounded-md border border-line bg-surface p-4 shadow-card">
                    <p class="mb-2.5 font-display text-16 font-bold tracking-[-0.01em] text-ink">Recent messages</p>
                    @if ($detail['messages']->isEmpty())
                        <p class="text-13 text-muted">No SMS reminders sent yet.</p>
                    @else
                        <ul class="space-y-2 text-13">
                            @foreach ($detail['messages'] as $log)
                                <li class="flex items-start gap-2">
                                    <span class="loz mt-0.5 {{ $log->status === \App\Enums\NotificationStatus::Sent ? 'loz-success' : 'loz-danger' }}">{{ $log->status->label() }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-subtle">{{ $log->content }}</span>
                                        <span class="text-12 text-muted">{{ $log->created_at->format('j M Y · H:i') }} · {{ $log->recipient }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
