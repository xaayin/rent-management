<div wire:keydown.escape.window="closeOverlays">
    <x-toast />

    <nav class="mb-1.5 flex items-center gap-1.5 text-12 text-muted"><span>Billing</span><span>/</span><span class="text-subtle">Bank transfers</span></nav>
    <div class="mb-4 flex items-end justify-between gap-4">
        <div>
            <h1 class="text-24 font-semibold text-ink">Bank transfers</h1>
            <p class="mt-0.5 text-13 text-subtle">Transfers tenants say they've made — confirm to record the payment, or reject with a reason (T3).</p>
        </div>
    </div>

    <div class="mb-2 flex items-center gap-1 border-b border-line-2">
        <button wire:click="$set('tab', 'pending')" class="tab {{ $tab === 'pending' ? 'tab-active' : '' }}">
            Pending
            @if ($pendingCount > 0)
                <span class="ml-1.5 rounded-full bg-danger-bg px-1.5 py-0.5 text-11 font-bold text-danger-fg">{{ $pendingCount }}</span>
            @endif
        </button>
        <button wire:click="$set('tab', 'history')" class="tab {{ $tab === 'history' ? 'tab-active' : '' }}">Decided</button>
    </div>

    <div class="overflow-hidden rounded-md border border-line bg-surface shadow-card">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[820px] text-13">
                <thead>
                    <tr class="border-b border-line">
                        <th class="th text-left">Tenant</th>
                        <th class="th text-right">Amount</th>
                        <th class="th text-left">Transfer date</th>
                        <th class="th text-left">Reference</th>
                        <th class="th text-right">{{ $tab === 'pending' ? 'Decision' : 'Outcome' }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-2">
                    @forelse ($claims as $claim)
                        <tr class="align-top">
                            <td class="td">
                                <p class="font-medium text-ink">{{ $claim->tenant->name }}</p>
                                <p class="text-12 text-muted">owes {{ $claim->tenant->outstandingBalance()->format() }}</p>
                            </td>
                            <td class="td whitespace-nowrap text-right font-semibold tabular-nums text-ink">{{ $claim->amount()->format() }}</td>
                            <td class="td whitespace-nowrap text-subtle">{{ $claim->transfer_date->format('j M Y') }}</td>
                            <td class="td">
                                {{ $claim->bank_reference }}
                                @if ($claim->note)
                                    <span class="block text-12 text-muted">{{ $claim->note }}</span>
                                @endif
                            </td>
                            <td class="td text-right">
                                @if ($claim->isPending())
                                    <span class="flex items-center justify-end gap-1.5">
                                        <button wire:click="confirm({{ $claim->id }})" class="btn-primary">Confirm</button>
                                        <button wire:click="startReject({{ $claim->id }})" class="btn-secondary text-danger-fg">Reject</button>
                                    </span>
                                @else
                                    <span class="loz {{ match ($claim->status) {
                                        \App\Enums\TransferClaimStatus::Confirmed => 'loz-success',
                                        \App\Enums\TransferClaimStatus::Rejected => 'loz-danger',
                                        default => 'loz-neutral',
                                    } }}">{{ $claim->status->label() }}</span>
                                    <span class="mt-1 block text-12 text-muted">
                                        {{ $claim->decider?->name ? $claim->decider->name.' · ' : '' }}{{ $claim->decided_at?->format('j M Y') }}
                                    </span>
                                    @if ($claim->receipt)
                                        <span class="mt-0.5 block text-12 text-subtle">Receipt {{ $claim->receipt->number }}</span>
                                    @elseif ($claim->decision_note)
                                        <span class="mt-0.5 block text-12 text-subtle">“{{ $claim->decision_note }}”</span>
                                    @endif
                                @endif
                            </td>
                        </tr>

                        @if ($rejectingId === $claim->id)
                            <tr class="bg-danger-bg/20">
                                <td colspan="5" class="px-4 py-3">
                                    <label class="fl text-danger-fg">Why are you rejecting this transfer?</label>
                                    <input type="text" wire:model="decision_note" class="input mt-1.5" placeholder="e.g. No transfer found against this reference.">
                                    @error('decision_note') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                                    <div class="mt-2 flex gap-2">
                                        <button wire:click="confirmReject" class="btn-danger">Confirm rejection</button>
                                        <button wire:click="closeOverlays" class="btn-subtle">Cancel</button>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-13 text-muted">
                            {{ $tab === 'pending' ? 'No transfers waiting to be confirmed.' : 'No decisions recorded yet.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :paginator="$claims" />
    </div>
</div>
