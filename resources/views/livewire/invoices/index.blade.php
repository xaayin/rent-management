<div wire:keydown.escape.window="closeOverlays">
    <div class="mb-6 flex items-end justify-between">
        <div>
            <nav class="mb-1.5 flex items-center gap-1.5 text-12 text-muted"><span>Billing</span><span>/</span><span class="text-subtle">Invoices</span></nav>
            <h1 class="text-24 font-semibold text-ink">Invoices</h1>
            <p class="text-13 text-muted">Automatically generated each cycle from active lease terms (PRD §4.5).</p>
        </div>
        <div class="flex items-end gap-2">
            <div>
                <label class="fl mb-1 block">Billing month</label>
                <input type="month" wire:model="period" class="input h-8 w-40">
            </div>
            <button wire:click="generate" wire:loading.attr="disabled" class="btn-subtle border border-line">
                <span wire:loading.remove wire:target="generate">Generate this month</span>
                <span wire:loading wire:target="generate">Generating…</span>
            </button>
            <button wire:click="openCreateInvoice" class="btn-primary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                New invoice
            </button>
        </div>
    </div>

    @error('period') <p class="mb-3 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
    <x-toast />

    {{-- ============ Toolbar: filter + chips (design PRD §5.5) ============ --}}
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <label class="relative">
            <span class="absolute inset-y-0 left-2.5 grid place-items-center text-muted">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            </span>
            <input type="text" wire:model.live.debounce.300ms="q" placeholder="Filter invoices"
                class="h-8 w-56 rounded border border-line bg-surface pl-8 pr-3 text-13 shadow-xs transition-[border-color,box-shadow] duration-100 placeholder:text-muted focus:border-brand-500 focus:ring-2 focus:ring-brand-300/40 focus-visible:outline-none">
        </label>

        <x-select wire:model.live="statusFilter" chip
            :options="collect(['' => 'Status'])->merge(collect($invoiceStatuses)->mapWithKeys(fn ($s) => [$s->value => $s->label()]))" />

        <x-select wire:model.live="tenantTypeFilter" chip
            :options="collect(['' => 'Tenant type'])->merge(collect($tenantTypes)->mapWithKeys(fn ($t) => [$t->value => $t->label()]))" />

        <x-select wire:model.live="propertyTypeFilter" chip
            :options="collect(['' => 'Property type'])->merge(collect($usageTypes)->mapWithKeys(fn ($u) => [$u->value => $u->label()]))" />

        <input type="month" wire:model.live="periodFilter" title="Billing period"
            class="chip {{ $periodFilter !== '' ? 'border-brand-500 text-brand-600' : '' }}">

        @if ($q !== '' || $statusFilter !== '' || $tenantTypeFilter !== '' || $propertyTypeFilter !== '' || $periodFilter !== '')
            <button wire:click="clearFilters" class="btn-subtle h-8 px-2 text-12">Clear filters</button>
        @endif

        <div class="ml-auto text-12 text-muted">{{ $invoices->total() }} invoice{{ $invoices->total() === 1 ? '' : 's' }}</div>
    </div>

    <div class="overflow-hidden rounded-md border border-line bg-surface shadow-card">
        <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-line">
                    <th class="th text-left">Number</th>
                    <th class="th text-left">Tenant</th>
                    <th class="th text-left">Property</th>
                    <th class="th text-left">Period</th>
                    <th class="th text-left">Due</th>
                    <th class="th text-right">Rent</th>
                    <th class="th text-right">Charges</th>
                    <th class="th text-right">Fine</th>
                    <th class="th text-right">Total</th>
                    <th class="th text-left">Status</th>
                    <th class="th text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoices as $invoice)
                    <tr class="border-b border-line-2 last:border-0 hover:bg-hover">
                        <td class="px-4 py-3 font-medium text-ink">{{ $invoice->number }}</td>
                        <td class="px-4 py-3 text-subtle">{{ $invoice->lease->tenant->name }}</td>
                        <td class="px-4 py-3 text-subtle">{{ $invoice->lease->property->name }}</td>
                        <td class="px-4 py-3 tabular-nums text-subtle whitespace-nowrap">
                            {{ $invoice->periodLabel() }}
                            @if ($invoice->period_months > 1)
                                <span class="loz loz-info ml-1">{{ $invoice->period_months }} mo</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap tabular-nums text-subtle">{{ $invoice->due_date->format('j M Y') }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-right tabular-nums text-ink">{{ $invoice->rent()->format() }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-right tabular-nums text-subtle">{{ $invoice->charges()->format() }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-right tabular-nums {{ $invoice->fine_laari > 0 ? 'font-medium text-danger-fg' : 'text-subtle' }}">{{ $invoice->fine()->format() }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-right font-medium tabular-nums text-ink">{{ $invoice->total()->format() }}</td>
                        <td class="px-4 py-3">
                            @php
                                $badge = match ($invoice->status) {
                                    \App\Enums\InvoiceStatus::Paid => 'loz-success',
                                    \App\Enums\InvoiceStatus::PartlyPaid => 'loz-warning',
                                    \App\Enums\InvoiceStatus::Overdue => 'loz-danger',
                                    \App\Enums\InvoiceStatus::Issued => 'loz-info',
                                };
                            @endphp
                            <span class="loz {{ $badge }}">{{ $invoice->status->label() }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="flex items-center justify-end gap-0.5">
                                @can('view reports')
                                    <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" class="icon-btn" title="Invoice PDF" aria-label="Invoice PDF">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h6"/></svg>
                                    </a>
                                @endcan
                                @if ($invoice->status !== \App\Enums\InvoiceStatus::Paid)
                                    <button wire:click="sendReminder({{ $invoice->id }})" class="icon-btn" title="Send reminder" aria-label="Send reminder">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                                    </button>
                                    @can('create', \App\Models\Payment::class)
                                        <button wire:click="startPayment({{ $invoice->id }})" class="icon-btn text-brand-600 hover:bg-selected" title="Record payment" aria-label="Record payment">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                                        </button>
                                    @endcan
                                @endif
                            </span>
                        </td>
                    </tr>

                @empty
                    <tr><td colspan="11" class="px-4 py-8 text-center text-[13px] text-muted">
                        @if ($q !== '' || $statusFilter !== '' || $tenantTypeFilter !== '' || $propertyTypeFilter !== '' || $periodFilter !== '')
                            No invoices match this view — clear the filters.
                        @else
                            No invoices yet — pick a month and generate.
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
        <x-pagination :paginator="$invoices" />
    </div>

    {{-- ============ Slide-over: record payment (design PRD §5.7/§5.8) ============ --}}
    @if ($paying !== null)
        @php $payingInvoice = $paying['invoice']; @endphp
        <div wire:click="cancelPayment" class="overlay-enter fixed inset-0 z-40 bg-navy/40 backdrop-blur-[2px]" aria-hidden="true"></div>
        <div class="slideover-enter fixed bottom-0 right-0 top-0 z-50 flex w-full max-w-[560px] flex-col bg-surface shadow-overlay" role="dialog" aria-modal="true">
            {{-- header --}}
            <div class="flex items-start gap-3 border-b border-line-2 px-5 py-4">
                <div class="min-w-0 flex-1">
                    <div class="mb-1 flex items-center gap-2 text-11 font-bold uppercase tracking-[0.08em] text-faint">
                        <span>Invoice {{ $payingInvoice->number }}</span><span>·</span><span>{{ $payingInvoice->periodLabel() }}</span>
                    </div>
                    <h2 class="truncate text-24 font-bold text-ink">Record payment</h2>
                    <p class="truncate text-13 text-muted">{{ $payingInvoice->lease->tenant->name }} · {{ $payingInvoice->lease->property->name }}</p>
                </div>
                <button wire:click="cancelPayment" class="icon-btn" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            {{-- body --}}
            <div class="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                {{-- due summary (live for the chosen payment date) --}}
                <div class="space-y-1.5 rounded-md border border-line-2 bg-sunken p-3 text-13">
                    <div class="flex justify-between"><span class="text-subtle">Rent &amp; charges outstanding</span><span class="tabular-nums">{{ $paying['outstanding_principal']->format() }}</span></div>
                    <div class="flex justify-between"><span class="text-subtle">Fine as of payment date</span><span class="tabular-nums">{{ $paying['outstanding_fine']->format() }}</span></div>
                    <div class="mt-1.5 flex justify-between border-t border-line pt-1.5 font-semibold"><span>Total due</span><span class="tabular-nums">{{ $paying['outstanding_total']->format() }}</span></div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="fl-req">Amount received (MVR)</label>
                        <input type="text" wire:model.live="pay_amount" placeholder="0.00" class="input mt-1 text-right tabular-nums">
                        @error('pay_amount') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="fl-req">Payment date</label>
                        <input type="date" wire:model.live="pay_date" class="input mt-1">
                        @error('pay_date') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="fl-req">Method</label>
                        <x-select wire:model="pay_method" class="mt-1"
                            :options="collect($methods)->mapWithKeys(fn ($m) => [$m->value => $m->label()])" />
                        @error('pay_method') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="fl">Reference</label>
                        <input type="text" wire:model="pay_reference" placeholder="e.g. BML txn 88421" class="input mt-1">
                        @error('pay_reference') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- live allocation (design PRD §5.8) --}}
                @if ($paying['allocation'] !== null)
                    <div class="space-y-1.5 rounded-md border border-line-2 bg-sunken p-3 text-13">
                        <p class="fl">This payment allocates</p>
                        <div class="flex justify-between"><span class="text-subtle">To rent &amp; charges</span><span class="tabular-nums">{{ $paying['allocation']['principal']->format() }}</span></div>
                        <div class="flex justify-between"><span class="text-subtle">To fine</span><span class="tabular-nums">{{ $paying['allocation']['fine']->format() }}</span></div>
                        @if ($paying['allocation']['excess'] > 0)
                            <p class="pt-1 text-13 text-danger-fg">Exceeds the outstanding balance.</p>
                        @endif
                        <p class="pt-1 text-11 text-muted">Allocation: rent first, then fine. The fine is computed on the payment date.</p>
                    </div>
                @endif

                {{-- payments on this invoice --}}
                @if ($payingInvoice->payments->isNotEmpty())
                    <div>
                        <p class="card-title mb-2">Payments on this invoice</p>
                        <div class="overflow-hidden rounded-md border border-line">
                            <table class="w-full text-13">
                                <tbody class="divide-y divide-line-2">
                                    @foreach ($payingInvoice->payments as $payment)
                                        <tr>
                                            <td class="px-3 py-2">
                                                @if ($payment->isReversal())
                                                    <span class="font-medium text-ink">Reversal of {{ $payment->reference }}</span>
                                                    <span class="block text-12 text-muted">{{ $payment->reversal_reason }}</span>
                                                @else
                                                    <span class="font-medium text-ink">Receipt {{ $payment->receipt_number }}</span>
                                                    @if ($payment->isReversed()) <span class="loz loz-danger ml-1">Reversed</span> @endif
                                                    <span class="block text-12 text-muted">{{ $payment->payment_date->format('j M Y') }} · {{ $payment->method->label() }} · rent {{ $payment->principalAllocated()->format() }} · fine {{ $payment->fineAllocated()->format() }}</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-right tabular-nums">{{ $payment->amount()->format() }}</td>
                                            <td class="w-20 px-2 py-2">
                                                @if (! $payment->isReversal())
                                                    <span class="flex items-center justify-end gap-0.5">
                                                        @can('view reports')
                                                            <a href="{{ route('payments.receipt', $payment) }}" target="_blank" class="icon-btn h-7 w-7" title="Receipt PDF" aria-label="Receipt PDF">
                                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M8 7h8M8 11h8M8 15h5"/></svg>
                                                            </a>
                                                        @endcan
                                                        @if (! $payment->isReversed())
                                                            @can('reverse', $payment)
                                                                <button wire:click="startReverse({{ $payment->id }})" class="icon-btn h-7 w-7 text-danger-fg hover:bg-danger-bg" title="Reverse payment" aria-label="Reverse payment">
                                                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                                                                </button>
                                                            @endcan
                                                        @endif
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                        @if ($reversingPaymentId === $payment->id)
                                            <tr class="bg-danger-bg/20">
                                                <td colspan="3" class="px-3 py-2.5">
                                                    <label class="fl text-danger-fg">Reason for reversing {{ $payment->receipt_number }}</label>
                                                    <input type="text" wire:model="reversal_reason" class="input mt-1.5">
                                                    @error('reversal_reason') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                                                    <div class="mt-2 flex gap-2">
                                                        <button wire:click="confirmReverse" class="btn-danger">Confirm reversal</button>
                                                        <button wire:click="$set('reversingPaymentId', null)" class="btn-subtle">Cancel</button>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>

            {{-- footer --}}
            <div class="flex items-center justify-end gap-2 border-t border-line-2 bg-sunken px-5 py-3.5">
                <button wire:click="cancelPayment" class="btn-subtle">Cancel</button>
                <button wire:click="confirmPayment" class="btn-primary">Record payment</button>
            </div>
        </div>
    @endif

    {{-- ============ Modal: new invoice / advance billing (FR-INV-05) ============ --}}
    @if ($creatingInvoice)
        <x-modal title="New invoice" close="closeCreateInvoice">
            <div class="space-y-4 px-5 py-4">
                <div>
                    <label class="fl-req">Lease</label>
                    <x-select wire:model.live="inv_lease_id" class="mt-1" placeholder="Pick a lease…"
                        :options="$activeLeases->mapWithKeys(fn ($l) => [$l->id => $l->agreement_number.' — '.$l->tenant->name.' · '.$l->property->name])" />
                    @error('inv_lease_id') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="fl-req">First billing month</label>
                        <input type="month" wire:model.live="inv_start" class="input mt-1">
                        @error('inv_start') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="fl-req">Months covered</label>
                        <input type="number" wire:model.live="inv_months" min="1" class="input mt-1 text-right tabular-nums">
                        @error('inv_months') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- quick picks --}}
                <div class="flex flex-wrap items-center gap-1.5">
                    @foreach ([1 => '1 month', 3 => '3 months', 6 => '6 months', 12 => '1 year'] as $n => $label)
                        <button type="button" wire:click="setMonths({{ $n }})"
                            class="chip h-7 px-2 text-12 {{ $inv_months === $n ? 'border-brand-500 text-brand-600' : '' }}">{{ $label }}</button>
                    @endforeach
                    <button type="button" wire:click="setMonthsUntilLeaseEnd" class="chip h-7 px-2 text-12">Until lease end</button>
                </div>

                {{-- live preview --}}
                @if ($newInvoice !== null)
                    <div class="space-y-1.5 rounded-md border border-line-2 bg-sunken p-3 text-13">
                        <div class="flex justify-between">
                            <span class="text-subtle">Period covered</span>
                            <span class="font-medium">{{ $newInvoice['label'] }} <span class="text-muted">({{ $newInvoice['months'] }} month{{ $newInvoice['months'] === 1 ? '' : 's' }})</span></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-subtle">Rent — {{ $newInvoice['months'] }} × {{ $newInvoice['monthly_rent']->format() }}</span>
                            <span class="tabular-nums">{{ $newInvoice['rent']->format() }}</span>
                        </div>
                        @if ($newInvoice['csr_occurrences'] > 0)
                            <div class="flex justify-between">
                                <span class="text-subtle">CSR charge × {{ $newInvoice['csr_occurrences'] }}</span>
                                <span class="tabular-nums">{{ $newInvoice['charges']->format() }}</span>
                            </div>
                        @endif
                        <div class="mt-1.5 flex justify-between border-t border-line pt-1.5 font-semibold">
                            <span>Invoice total</span>
                            <span class="tabular-nums">{{ $newInvoice['total']->format() }}</span>
                        </div>
                        <p class="pt-1 text-11 text-muted">Due {{ $newInvoice['due_date'] }} · one invoice, one payment.</p>
                        @if ($newInvoice['error'] !== null)
                            <p class="pt-1 text-13 text-danger-fg">{{ $newInvoice['error'] }}</p>
                        @endif
                    </div>
                @endif
            </div>
            <div class="flex items-center justify-end gap-2 rounded-b-xl border-t border-line-2 bg-sunken px-5 py-3.5">
                <button type="button" wire:click="closeCreateInvoice" class="btn-subtle">Cancel</button>
                <button type="button" wire:click="createInvoice" class="btn-primary" @disabled($newInvoice !== null && $newInvoice['error'] !== null)>
                    Create invoice
                </button>
            </div>
        </x-modal>
    @endif
</div>
