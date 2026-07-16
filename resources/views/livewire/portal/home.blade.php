<div>
    {{-- who's signed in + sign out --}}
    <div class="mb-4 flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-11 font-bold uppercase tracking-[0.08em] text-faint">Your account</p>
            <h1 class="truncate text-20 font-bold text-ink">{{ $tenant->name }}</h1>
            <p class="text-12 text-muted">{{ $tenant->type->label() }}{{ $tenant->registryNumber() ? ' · '.$tenant->registryNumber() : '' }}</p>
        </div>
        <button wire:click="logout" class="btn-subtle shrink-0">Sign out</button>
    </div>

    {{-- the headline figure --}}
    @if ($balance->isPositive())
        <div class="mb-4 rounded-md bg-danger-bg px-5 py-4">
            <p class="text-12 font-bold uppercase tracking-[0.08em] text-danger-fg">You currently owe</p>
            <p class="mt-1 font-display text-[28px] font-extrabold leading-8 tracking-[-0.02em] tabular-nums text-danger-fg">{{ $balance->format() }}</p>
            <p class="mt-1 text-12 text-danger-fg">across {{ $outstandingCount }} invoice{{ $outstandingCount === 1 ? '' : 's' }} · pay to {{ config('billing.payment_account') }}</p>
        </div>
    @else
        <div class="mb-4 rounded-md bg-success-bg px-5 py-4">
            <p class="text-12 font-bold uppercase tracking-[0.08em] text-success-fg">Nothing outstanding</p>
            <p class="mt-1 font-display text-[28px] font-extrabold leading-8 tracking-[-0.02em] tabular-nums text-success-fg">MVR 0.00</p>
        </div>
    @endif

    @if (session('portal_status'))
        <div class="mb-4 rounded-md border border-success-bg bg-success-bg/60 px-4 py-3 text-13 text-success-fg">{{ session('portal_status') }}</div>
    @endif

    <div class="mb-3 flex items-center gap-1 overflow-x-auto border-b border-line-2">
        @foreach (['account' => 'My leases', 'invoices' => 'Invoices', 'receipts' => 'Receipts', 'statement' => 'Statement', 'pay' => 'Pay by transfer'] as $key => $label)
            <button wire:click="setTab('{{ $key }}')" class="tab whitespace-nowrap {{ $tab === $key ? 'tab-active' : '' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'account')
        <div class="space-y-3">
            @forelse ($leases as $lease)
                <div class="rounded-md border border-line bg-surface p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-13 font-semibold text-ink">{{ $lease->property->name }}</p>
                            <p class="text-12 text-muted">{{ $lease->agreement_number }}</p>
                        </div>
                        <span class="loz {{ match ($lease->status) {
                            \App\Enums\LeaseStatus::Active => 'loz-success',
                            \App\Enums\LeaseStatus::Terminated => 'loz-danger',
                            default => 'loz-neutral',
                        } }}">{{ $lease->status->label() }}</span>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-2 text-13">
                        <div><p class="fl">Monthly rent</p><p class="fv tabular-nums">{{ $lease->monthlyRent()->format() }}</p></div>
                        <div><p class="fl">Due day</p><p class="fv">Day {{ $lease->due_day }} of month</p></div>
                        <div><p class="fl">Start</p><p class="fv">{{ $lease->start_date->format('j M Y') }}</p></div>
                        <div><p class="fl">Expiry</p><p class="fv">{{ $lease->expiry_date->format('j M Y') }}</p></div>
                    </div>
                </div>
            @empty
                <p class="py-8 text-center text-13 text-muted">No leases on this account.</p>
            @endforelse
        </div>
    @elseif ($tab === 'invoices')
        <div class="overflow-hidden rounded-md border border-line bg-surface shadow-card">
            <div class="divide-y divide-line-2">
                @forelse ($invoices as $invoice)
                    <div class="flex items-center gap-3 px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-13 font-semibold text-ink">{{ $invoice->number }} <span class="font-normal text-muted">· {{ $invoice->periodLabel() }}</span></p>
                            <p class="text-12 text-muted">{{ $invoice->lease->property->name }} · due {{ $invoice->due_date->format('j M Y') }}</p>
                        </div>
                        <span class="loz {{ match ($invoice->status) {
                            \App\Enums\InvoiceStatus::Paid => 'loz-success',
                            \App\Enums\InvoiceStatus::PartlyPaid => 'loz-warning',
                            \App\Enums\InvoiceStatus::Overdue => 'loz-danger',
                            default => 'loz-info',
                        } }}">{{ $invoice->status->label() }}</span>
                        <span class="w-24 whitespace-nowrap text-right text-13 tabular-nums {{ in_array($invoice->status, $unsettledStatuses, true) ? 'font-semibold text-ink' : 'text-subtle' }}">
                            {{ $invoice->total()->format() }}
                        </span>
                        <a href="{{ route('portal.invoices.pdf', $invoice) }}" target="_blank" class="icon-btn shrink-0" title="Download PDF" aria-label="Download invoice {{ $invoice->number }} PDF">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                        </a>
                    </div>
                @empty
                    <p class="px-4 py-8 text-center text-13 text-muted">No invoices yet.</p>
                @endforelse
            </div>
        </div>
    @elseif ($tab === 'receipts')
        <div class="overflow-hidden rounded-md border border-line bg-surface shadow-card">
            <div class="divide-y divide-line-2">
                @forelse ($receipts as $receipt)
                    <div class="flex items-center gap-3 px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-13 font-semibold text-ink">Receipt {{ $receipt->number }}</p>
                            <p class="text-12 text-muted">
                                {{ $receipt->payments->first()?->payment_date->format('j M Y') }}
                                · {{ $receipt->payments->count() }} invoice{{ $receipt->payments->count() === 1 ? '' : 's' }}
                            </p>
                        </div>
                        <span class="whitespace-nowrap text-right text-13 font-semibold tabular-nums text-ink">{{ $receipt->total()->format() }}</span>
                        <a href="{{ route('portal.receipts.pdf', $receipt) }}" target="_blank" class="icon-btn shrink-0" title="Download PDF" aria-label="Download receipt {{ $receipt->number }} PDF">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                        </a>
                    </div>
                @empty
                    <p class="px-4 py-8 text-center text-13 text-muted">No receipts yet.</p>
                @endforelse
            </div>
        </div>
    @elseif ($tab === 'pay')
        <div class="space-y-4">
            {{-- how to pay --}}
            <div class="rounded-md border border-line bg-surface p-4 shadow-card">
                <p class="font-display text-16 font-bold tracking-[-0.01em] text-ink">Pay by bank transfer</p>
                <p class="mt-1 text-13 text-muted">Transfer to the council's account, then tell us here. Finance will confirm it and update your balance — no need to come to the office.</p>
                <div class="mt-3 rounded border border-line-2 bg-sunken px-4 py-3 text-13">
                    <p class="fl">Council account</p>
                    <p class="fv font-medium">{{ config('billing.payment_account') }}</p>
                </div>
            </div>

            @if ($pendingClaim)
                {{-- one at a time: show the open claim instead of the form --}}
                <div class="rounded-md border border-warning-bg bg-warning-bg/40 p-4">
                    <p class="text-12 font-bold uppercase tracking-[0.08em] text-warning-fg">Awaiting confirmation</p>
                    <p class="mt-1 text-13 text-ink">You told us about a transfer of <span class="font-semibold tabular-nums">{{ $pendingClaim->amount()->format() }}</span> (ref {{ $pendingClaim->bank_reference }}) on {{ $pendingClaim->transfer_date->format('j M Y') }}.</p>
                    <p class="mt-1 text-12 text-muted">The council will confirm it shortly. You'll see it on your statement once done.</p>
                </div>
            @else
                <form wire:submit="submitClaim" class="rounded-md border border-line bg-surface p-4 shadow-card">
                    <p class="mb-3 text-13 font-semibold text-ink">I've paid by bank transfer</p>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="fl-req">Amount transferred (MVR)</label>
                            <input type="text" wire:model="claim_amount" placeholder="0.00" class="input mt-1 text-right tabular-nums">
                            @error('claim_amount') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="fl-req">Date of transfer</label>
                            <input type="date" wire:model="claim_date" class="input mt-1">
                            @error('claim_date') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                        <div class="col-span-2">
                            <label class="fl-req">Bank reference</label>
                            <input type="text" wire:model="claim_reference" placeholder="e.g. BML transaction ref" class="input mt-1">
                            @error('claim_reference') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                        <div class="col-span-2">
                            <label class="fl">Note (optional)</label>
                            <input type="text" wire:model="claim_note" placeholder="anything the council should know" class="input mt-1">
                            @error('claim_note') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <button type="submit" class="btn-primary mt-4 w-full justify-center">
                        <span wire:loading.remove wire:target="submitClaim">Tell the council</span>
                        <span wire:loading wire:target="submitClaim">Sending…</span>
                    </button>
                </form>
            @endif

            {{-- history --}}
            @if ($claims->isNotEmpty())
                <div class="overflow-hidden rounded-md border border-line bg-surface shadow-card">
                    <p class="border-b border-line-2 px-4 py-2.5 text-13 font-semibold text-ink">Your transfers</p>
                    <div class="divide-y divide-line-2">
                        @foreach ($claims as $claim)
                            <div class="px-4 py-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-13 font-medium text-ink tabular-nums">{{ $claim->amount()->format() }} <span class="font-normal text-muted">· {{ $claim->bank_reference }}</span></p>
                                        <p class="text-12 text-muted">{{ $claim->transfer_date->format('j M Y') }}</p>
                                    </div>
                                    <span class="loz {{ match ($claim->status) {
                                        \App\Enums\TransferClaimStatus::Confirmed => 'loz-success',
                                        \App\Enums\TransferClaimStatus::Rejected => 'loz-danger',
                                        default => 'loz-warning',
                                    } }}">{{ $claim->status->label() }}</span>
                                </div>
                                @if ($claim->status === \App\Enums\TransferClaimStatus::Rejected && $claim->decision_note)
                                    <p class="mt-1.5 text-12 text-danger-fg">Council note: {{ $claim->decision_note }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @else
        <div class="overflow-hidden rounded-md border border-line bg-surface shadow-card">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[560px] text-13">
                    <thead>
                        <tr class="border-b border-line">
                            <th class="th text-left">Date</th>
                            <th class="th text-left">Entry</th>
                            <th class="th text-right">Debit</th>
                            <th class="th text-right">Credit</th>
                            <th class="th text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line-2">
                        @forelse ($entries as $entry)
                            <tr>
                                <td class="td whitespace-nowrap text-subtle">{{ \Illuminate\Support\Carbon::parse($entry['date'])->format('j M Y') }}</td>
                                <td class="td">
                                    <p class="font-medium text-ink">{{ $entry['label'] }}</p>
                                    <p class="text-12 text-muted">{{ $entry['detail'] }}</p>
                                </td>
                                <td class="td whitespace-nowrap text-right tabular-nums">{{ $entry['debit'] !== 0 ? \App\Support\Money::fromLaari($entry['debit'])->format() : '—' }}</td>
                                <td class="td whitespace-nowrap text-right tabular-nums">{{ $entry['credit'] !== 0 ? \App\Support\Money::fromLaari($entry['credit'])->format() : '—' }}</td>
                                <td class="td whitespace-nowrap text-right font-medium tabular-nums">{{ \App\Support\Money::fromLaari($entry['balance'])->format() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-13 text-muted">Nothing on the ledger yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($entriesBalance !== null)
                <div class="flex justify-between border-t border-line px-4 py-3 text-13 font-semibold">
                    <span>Closing balance</span>
                    <span class="tabular-nums {{ $entriesBalance->isPositive() ? 'text-danger-fg' : 'text-success-fg' }}">{{ $entriesBalance->format() }}</span>
                </div>
            @endif
        </div>
    @endif
</div>
