<div wire:keydown.escape.window="closeCreateInvoice">
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
                class="h-8 w-56 rounded border border-line bg-surface pl-8 pr-3 text-13 placeholder:text-muted focus:border-brand-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-300">
        </label>

        <select wire:model.live="statusFilter" class="chip {{ $statusFilter !== '' ? 'border-brand-500 text-brand-600' : '' }}">
            <option value="">Status</option>
            @foreach ($invoiceStatuses as $statusOption)
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
                <tr class="border-b border-line bg-sunken text-[11px] uppercase tracking-wide text-muted">
                    <th class="px-4 py-2 font-semibold">Number</th>
                    <th class="px-4 py-2 font-semibold">Tenant</th>
                    <th class="px-4 py-2 font-semibold">Property</th>
                    <th class="px-4 py-2 font-semibold">Period</th>
                    <th class="px-4 py-2 font-semibold">Due</th>
                    <th class="px-4 py-2 text-right font-semibold">Rent</th>
                    <th class="px-4 py-2 text-right font-semibold">Charges</th>
                    <th class="px-4 py-2 text-right font-semibold">Fine</th>
                    <th class="px-4 py-2 text-right font-semibold">Total</th>
                    <th class="px-4 py-2 font-semibold">Status</th>
                    <th class="px-4 py-2 text-right font-semibold">Actions</th>
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
                        <td class="px-4 py-3 tabular-nums text-subtle">{{ $invoice->due_date->toDateString() }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink">{{ $invoice->rent()->format() }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-subtle">{{ $invoice->charges()->format() }}</td>
                        <td class="px-4 py-3 text-right tabular-nums {{ $invoice->fine_laari > 0 ? 'font-medium text-danger-fg' : 'text-subtle' }}">{{ $invoice->fine()->format() }}</td>
                        <td class="px-4 py-3 text-right font-medium tabular-nums text-ink">{{ $invoice->total()->format() }}</td>
                        <td class="px-4 py-3">
                            @php
                                $badge = match ($invoice->status) {
                                    \App\Enums\InvoiceStatus::Paid => 'bg-success-bg text-success-fg',
                                    \App\Enums\InvoiceStatus::PartlyPaid => 'bg-warning-bg text-warning-fg',
                                    \App\Enums\InvoiceStatus::Overdue => 'bg-danger-bg text-danger-fg',
                                    \App\Enums\InvoiceStatus::Issued => 'bg-info-bg text-info-fg',
                                };
                            @endphp
                            <span class="inline-flex items-center rounded-sm px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $badge }}">{{ $invoice->status->label() }}</span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @can('view reports')
                                <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" class="rounded px-2 py-1 text-[13px] font-medium text-subtle hover:bg-hover">PDF</a>
                            @endcan
                            @if ($invoice->status !== \App\Enums\InvoiceStatus::Paid)
                                <button wire:click="sendReminder({{ $invoice->id }})" class="rounded px-2 py-1 text-[13px] font-medium text-subtle hover:bg-hover">Remind</button>
                            @endif
                            @can('create', \App\Models\Payment::class)
                                @if ($invoice->status !== \App\Enums\InvoiceStatus::Paid)
                                    <button wire:click="startPayment({{ $invoice->id }})" class="rounded px-2 py-1 text-[13px] font-medium text-brand-600 hover:bg-hover">Record payment</button>
                                @endif
                            @endcan
                        </td>
                    </tr>

                    @if ($paying !== null && $paying['invoice']->id === $invoice->id)
                        <tr class="bg-sunken">
                            <td colspan="11" class="px-4 py-4">
                                <p class="mb-3 text-[11px] font-semibold uppercase tracking-wide text-subtle">Record payment — invoice {{ $invoice->number }}</p>

                                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                                    <div class="grid grid-cols-2 gap-3 lg:col-span-2">
                                        <div>
                                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Amount (MVR)</label>
                                            <input type="text" wire:model.live="pay_amount" placeholder="0.00" class="h-9 w-full rounded border border-line bg-surface px-3 text-right text-sm tabular-nums text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                                            @error('pay_amount') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Payment date</label>
                                            <input type="date" wire:model.live="pay_date" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                                            @error('pay_date') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Method</label>
                                            <select wire:model="pay_method" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                                                @foreach ($methods as $method)
                                                    <option value="{{ $method->value }}">{{ $method->label() }}</option>
                                                @endforeach
                                            </select>
                                            @error('pay_method') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Reference</label>
                                            <input type="text" wire:model="pay_reference" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                                            @error('pay_reference') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                                        </div>
                                        <div class="col-span-2 flex gap-2">
                                            <button wire:click="confirmPayment" class="h-8 rounded bg-brand-500 px-4 text-sm font-medium text-white transition hover:bg-brand-600 active:bg-brand-700">Record payment</button>
                                            <button wire:click="cancelPayment" class="h-8 rounded px-4 text-sm font-medium text-subtle transition hover:bg-hover">Cancel</button>
                                        </div>
                                    </div>

                                    {{-- Live allocation card (design PRD §5.8, FR-PAY-02) --}}
                                    <div class="rounded-md border border-line bg-surface p-4 shadow-card">
                                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted">Due as of the payment date</p>
                                        <dl class="space-y-1 text-[13px]">
                                            <div class="flex justify-between"><dt class="text-subtle">Rent &amp; charges outstanding</dt><dd class="tabular-nums text-ink">{{ $paying['outstanding_principal']->format() }}</dd></div>
                                            <div class="flex justify-between"><dt class="text-subtle">Fine outstanding</dt><dd class="tabular-nums text-ink">{{ $paying['outstanding_fine']->format() }}</dd></div>
                                            <div class="flex justify-between border-t border-line-2 pt-1 font-medium"><dt class="text-ink">Total due</dt><dd class="tabular-nums text-ink">{{ $paying['outstanding_total']->format() }}</dd></div>
                                        </dl>
                                        @if ($paying['allocation'] !== null)
                                            <p class="mb-1 mt-3 text-[11px] font-semibold uppercase tracking-wide text-muted">This payment allocates</p>
                                            <dl class="space-y-1 text-[13px]">
                                                <div class="flex justify-between"><dt class="text-subtle">To rent &amp; charges</dt><dd class="tabular-nums text-ink">{{ $paying['allocation']['principal']->format() }}</dd></div>
                                                <div class="flex justify-between"><dt class="text-subtle">To fine</dt><dd class="tabular-nums text-ink">{{ $paying['allocation']['fine']->format() }}</dd></div>
                                            </dl>
                                            @if ($paying['allocation']['excess'] > 0)
                                                <p class="mt-2 text-[13px] text-danger-fg">Exceeds the outstanding balance.</p>
                                            @endif
                                        @endif
                                    </div>
                                </div>

                                @if ($paying['invoice']->payments->isNotEmpty())
                                    <p class="mb-1 mt-4 text-[11px] font-semibold uppercase tracking-wide text-muted">Payments on this invoice</p>
                                    <table class="w-full text-left text-[13px]">
                                        <tbody>
                                            @foreach ($paying['invoice']->payments as $payment)
                                                <tr class="border-b border-line-2 last:border-0">
                                                    <td class="py-1.5 pr-3 font-medium text-ink">
                                                        @if ($payment->isReversal())
                                                            Reversal of {{ $payment->reference }}
                                                        @else
                                                            Receipt {{ $payment->receipt_number }}@if ($payment->isReversed()) <span class="text-danger-fg">(reversed)</span> @endif
                                                        @endif
                                                    </td>
                                                    <td class="py-1.5 pr-3 tabular-nums text-subtle">{{ $payment->payment_date->toDateString() }}</td>
                                                    <td class="py-1.5 pr-3 text-subtle">{{ $payment->method->label() }}</td>
                                                    <td class="py-1.5 pr-3 text-right tabular-nums text-ink">{{ $payment->amount()->format() }}</td>
                                                    <td class="py-1.5 pr-3 text-[12px] text-muted">rent {{ $payment->principalAllocated()->format() }} · fine {{ $payment->fineAllocated()->format() }}</td>
                                                    <td class="py-1.5 text-right whitespace-nowrap">
                                                        @if (! $payment->isReversal())
                                                            @can('view reports')
                                                                <a href="{{ route('payments.receipt', $payment) }}" target="_blank" class="rounded px-2 py-0.5 text-[12px] font-medium text-subtle hover:bg-hover">Receipt PDF</a>
                                                            @endcan
                                                            @if (! $payment->isReversed())
                                                                @can('reverse', $payment)
                                                                    <button wire:click="startReverse({{ $payment->id }})" class="rounded px-2 py-0.5 text-[12px] font-medium text-danger-fg hover:bg-hover">Reverse</button>
                                                                @endcan
                                                            @endif
                                                        @else
                                                            <span class="text-[12px] text-muted">{{ $payment->reversal_reason }}</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                                @if ($reversingPaymentId === $payment->id)
                                                    <tr>
                                                        <td colspan="6" class="py-2">
                                                            <div class="flex items-end gap-2">
                                                                <div class="flex-1">
                                                                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-danger-fg">Reason for reversing {{ $payment->receipt_number }}</label>
                                                                    <input type="text" wire:model="reversal_reason" class="h-9 w-full rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
                                                                    @error('reversal_reason') <p class="mt-1 text-[13px] text-danger-fg">{{ $message }}</p> @enderror
                                                                </div>
                                                                <button wire:click="confirmReverse" class="h-8 rounded bg-danger-fg px-4 text-sm font-medium text-white transition hover:opacity-90">Confirm reversal</button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                @endif
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif
                            </td>
                        </tr>
                    @endif
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

    {{-- ============ Modal: new invoice / advance billing (FR-INV-05) ============ --}}
    @if ($creatingInvoice)
        <x-modal title="New invoice" close="closeCreateInvoice">
            <div class="space-y-4 px-5 py-4">
                <div>
                    <label class="fl-req">Lease</label>
                    <select wire:model.live="inv_lease_id" class="input mt-1">
                        <option value="">Pick a lease…</option>
                        @foreach ($activeLeases as $leaseOption)
                            <option value="{{ $leaseOption->id }}">
                                {{ $leaseOption->agreement_number }} — {{ $leaseOption->tenant->name }} · {{ $leaseOption->property->name }}
                            </option>
                        @endforeach
                    </select>
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
            <div class="flex items-center justify-end gap-2 rounded-b-lg border-t border-line-2 bg-sunken px-5 py-3.5">
                <button type="button" wire:click="closeCreateInvoice" class="btn-subtle">Cancel</button>
                <button type="button" wire:click="createInvoice" class="btn-primary" @disabled($newInvoice !== null && $newInvoice['error'] !== null)>
                    Create invoice
                </button>
            </div>
        </x-modal>
    @endif
</div>
