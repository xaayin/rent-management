{{-- Collect ONE handover of money and spread it across a tenant's outstanding
     invoices, oldest first (FR-PAY-01/02). Shared by the Invoices screen (where
     a Finance Officer works) and the tenant slide-over (where a Supervisor
     does) — see App\Livewire\Concerns\CollectsTenantPayments.

     `preview` is the trait's buildCollectPreview() output; `methods` the
     PaymentMethod cases. --}}
@props(['preview', 'methods'])

<div class="rounded-md border border-line bg-surface p-4 shadow-card">
    <div class="mb-3 flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="font-display text-16 font-bold tracking-[-0.01em] text-ink">Record payment</p>
            <p class="mt-0.5 truncate text-12 text-muted">
                {{ $preview['tenant']->name }} · one receipt for the whole handover, applied oldest invoice first.
            </p>
        </div>
        <button type="button" wire:click="cancelCollect" class="icon-btn -mr-1 -mt-1" aria-label="Cancel">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label class="fl-req">Amount received (MVR)</label>
            <input type="text" wire:model.live.debounce.400ms="collect_amount" placeholder="0.00" class="input mt-1 text-right tabular-nums">
            @error('collect_amount') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="fl-req">Payment date</label>
            <input type="date" wire:model.live="collect_date" class="input mt-1">
            @error('collect_date') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="fl-req">Method</label>
            <x-select wire:model="collect_method" class="mt-1"
                :options="collect($methods)->mapWithKeys(fn ($m) => [$m->value => $m->label()])" />
        </div>
        <div>
            <label class="fl">Reference</label>
            <input type="text" wire:model="collect_reference" placeholder="e.g. BML txn 88421" class="input mt-1">
        </div>
    </div>

    {{-- Live allocation — where the money lands, before it is committed. --}}
    <div class="mt-4 overflow-hidden rounded border border-line-2">
        <div class="overflow-x-auto">
            <table class="w-full text-13">
                <thead>
                    <tr class="border-b border-line-2 bg-sunken">
                        <th class="th w-8"></th>
                        <th class="th text-left">Invoice</th>
                        <th class="th text-right">Due</th>
                        <th class="th text-right">Allocated</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-2">
                    @foreach ($preview['rows'] as $row)
                        <tr class="{{ $row['selected'] ? '' : 'opacity-45' }}">
                            <td class="td">
                                <input type="checkbox" wire:model.live="collect_invoice_ids" value="{{ $row['invoice']->id }}"
                                    class="rounded border-line text-brand-500 focus:ring-brand-300"
                                    aria-label="Include invoice {{ $row['invoice']->number }}">
                            </td>
                            <td class="td">
                                <span class="font-medium text-ink">{{ $row['invoice']->number }}</span>
                                <span class="block text-12 text-muted">
                                    {{ $row['invoice']->periodLabel() }} · due {{ $row['invoice']->due_date->format('j M Y') }}
                                </span>
                            </td>
                            <td class="td whitespace-nowrap text-right tabular-nums text-subtle">{{ $row['due']->format() }}</td>
                            <td class="td whitespace-nowrap text-right tabular-nums">
                                <span class="{{ $row['allocated']->isPositive() ? 'font-semibold text-ink' : 'text-faint' }}">
                                    {{ $row['allocated']->format() }}
                                </span>
                                @if ($row['settles'])
                                    <span class="loz loz-success ml-1">Settles</span>
                                @elseif ($row['allocated']->isPositive())
                                    <span class="block text-12 text-warning-fg">{{ $row['remaining']->format() }} left</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3 space-y-1.5 rounded-md border border-line-2 bg-sunken p-3 text-13">
        <div class="flex justify-between"><span class="text-subtle">Selected invoices owe</span><span class="tabular-nums">{{ $preview['total_due']->format() }}</span></div>
        <div class="flex justify-between font-semibold"><span>Amount received</span><span class="tabular-nums">{{ $preview['entered']->format() }}</span></div>
        @if ($preview['exceeds'])
            <p class="pt-1 text-13 text-danger-fg">
                That is {{ $preview['unapplied']->format() }} more than the selected invoices owe. The council does not hold credit balances — reduce the amount, or tick another invoice.
            </p>
        @endif
        <p class="pt-1 text-11 text-muted">Oldest invoice first; within each, rent before fine. Fines are computed as of the payment date.</p>
    </div>

    <div class="mt-3 flex gap-2">
        <button type="button" wire:click="confirmCollect" class="btn-primary" @disabled($preview['exceeds'])>Record payment</button>
        <button type="button" wire:click="cancelCollect" class="btn-subtle">Cancel</button>
    </div>
</div>
