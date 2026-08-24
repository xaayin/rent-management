{{-- Shared record-payment overlay, backed by the InteractsWithPayments trait.
     Used by the Leases list peek and the lease page, so there is one payment
     surface rather than two that can drift apart.

     Expects: $paying (buildPaymentPreview payload), $methods, and $unpaidChoices
     (an id => label map of the invoices the panel may switch between). --}}
@if ($paying !== null)
    <div class="overlay-enter fixed inset-0 z-[60] overflow-y-auto bg-navy/40 backdrop-blur-[2px]">
        <div wire:click.self="cancelPayment" class="flex min-h-full items-center justify-center p-4 sm:p-6">
            <div class="w-full max-w-[480px] rounded-xl bg-surface shadow-modal" role="dialog" aria-modal="true" aria-label="Record payment">
                <div class="flex items-start justify-between gap-3 px-5 pb-2 pt-5">
                    <h3 class="text-20 font-bold text-ink">Record payment</h3>
                    <button wire:click="cancelPayment" class="icon-btn -mr-1 -mt-1" aria-label="Close">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="space-y-4 px-5 py-4">
                    <div>
                        <label class="fl-req">Invoice</label>
                        <x-select wire:model.live="payingInvoiceId" class="mt-1" :options="$unpaidChoices" />
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
                            <x-select wire:model="pay_method" class="mt-1"
                                :options="collect($methods)->mapWithKeys(fn ($m) => [$m->value => $m->label()])" />
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
                <div class="flex items-center justify-end gap-2 rounded-b-xl border-t border-line-2 bg-sunken px-5 py-3.5">
                    <button wire:click="cancelPayment" class="btn-subtle">Cancel</button>
                    <button wire:click="confirmPayment" class="btn-primary">Record payment</button>
                </div>
            </div>
        </div>
    </div>
@endif
