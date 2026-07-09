<div>
    <div class="mb-6 flex items-end justify-between">
        <div>
            <nav class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted">Billing / Invoices</nav>
            <h1 class="text-2xl font-semibold text-ink">Invoices</h1>
            <p class="text-[13px] text-muted">Automatically generated each cycle from active lease terms (PRD §4.5).</p>
        </div>
        <div class="flex items-end gap-2">
            <div>
                <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-subtle">Billing month</label>
                <input type="month" wire:model="period" class="h-8 rounded border border-line bg-surface px-3 text-sm text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-300">
            </div>
            <button wire:click="generate" wire:loading.attr="disabled"
                class="h-8 rounded bg-brand-500 px-4 text-sm font-medium text-white transition hover:bg-brand-600 active:bg-brand-700 disabled:opacity-40">
                <span wire:loading.remove wire:target="generate">Generate this month</span>
                <span wire:loading wire:target="generate">Generating…</span>
            </button>
        </div>
    </div>

    @error('period') <p class="mb-3 text-[13px] text-danger-fg">{{ $message }}</p> @enderror

    @if (session('status'))
        <div class="mb-4 rounded border border-success-fg/20 bg-success-bg px-3 py-2 text-[13px] text-success-fg">
            {{ session('status') }}
        </div>
    @endif

    <div class="overflow-x-auto rounded-md border border-line bg-surface shadow-card">
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
                        <td class="px-4 py-3 tabular-nums text-subtle">{{ $invoice->period_start->format('M Y') }}</td>
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
                    <tr><td colspan="11" class="px-4 py-8 text-center text-[13px] text-muted">No invoices yet — pick a month and generate.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
