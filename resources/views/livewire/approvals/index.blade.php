<div wire:keydown.escape.window="closeOverlays">
    <x-toast />

    <nav class="mb-1.5 flex items-center gap-1.5 text-12 text-muted"><span>Council</span><span>/</span><span class="text-subtle">Approvals</span></nav>
    <div class="mb-4 flex items-end justify-between gap-4">
        <div>
            <h1 class="text-24 font-semibold text-ink">Approvals</h1>
            <p class="mt-0.5 text-13 text-subtle">Actions that need a supervisor's decision before they take effect (PRD §6.1).</p>
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
                        <th class="th text-left">Action</th>
                        <th class="th text-left">Record</th>
                        <th class="th text-left">Reason</th>
                        <th class="th text-left">Requested by</th>
                        <th class="th text-right">{{ $tab === 'pending' ? 'Decision' : 'Outcome' }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-2">
                    @forelse ($requests as $request)
                        <tr class="align-top">
                            <td class="td">
                                <span class="loz {{ $request->action === \App\Enums\ApprovalAction::TerminateLease ? 'loz-danger' : 'loz-warning' }}">
                                    {{ $request->action->label() }}
                                </span>
                            </td>
                            <td class="td font-medium text-ink">{{ $request->subjectSummary() }}</td>
                            <td class="td max-w-xs text-subtle">{{ $request->reason }}</td>
                            <td class="td">
                                <span class="block text-ink">{{ $request->requester->name }}</span>
                                <span class="block text-12 text-muted">{{ $request->requested_at->format('j M Y · H:i') }}</span>
                            </td>
                            <td class="td text-right">
                                @if ($request->isPending())
                                    <span class="flex items-center justify-end gap-1.5">
                                        <button wire:click="approve({{ $request->id }})" class="btn-primary">Approve</button>
                                        <button wire:click="startReject({{ $request->id }})" class="btn-secondary text-danger-fg">Reject</button>
                                    </span>
                                @else
                                    <span class="loz {{ match ($request->status) {
                                        \App\Enums\ApprovalStatus::Approved => 'loz-success',
                                        \App\Enums\ApprovalStatus::Rejected => 'loz-danger',
                                        default => 'loz-neutral',
                                    } }}">{{ $request->status->label() }}</span>
                                    <span class="mt-1 block text-12 text-muted">
                                        {{ $request->decider?->name ? $request->decider->name.' · ' : '' }}{{ $request->decided_at?->format('j M Y') }}
                                    </span>
                                    @if ($request->decision_note)
                                        <span class="mt-0.5 block text-12 text-subtle">“{{ $request->decision_note }}”</span>
                                    @endif
                                @endif
                            </td>
                        </tr>

                        {{-- Rejection carries a mandatory reason, so the requester learns why. --}}
                        @if ($rejectingId === $request->id)
                            <tr class="bg-danger-bg/20">
                                <td colspan="5" class="px-4 py-3">
                                    <label class="fl text-danger-fg">Why are you rejecting this?</label>
                                    <input type="text" wire:model="decision_note" class="input mt-1.5" placeholder="e.g. Lease still has 3 months to run.">
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
                            @if ($tab === 'pending')
                                Nothing waiting for approval.
                            @else
                                No decisions recorded yet.
                            @endif
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-pagination :paginator="$requests" />
    </div>
</div>
