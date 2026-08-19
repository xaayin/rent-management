<div wire:keydown.escape.window="closeOverlays">
    <x-toast />

    <nav class="mb-1.5 flex items-center gap-1.5 text-12 text-muted"><span>Billing</span><span>/</span><span class="text-subtle">Follow-ups</span></nav>
    <div class="mb-4 flex items-end justify-between gap-4">
        <div>
            <h1 class="text-24 font-semibold text-ink">Arrears follow-ups</h1>
            <p class="mt-1 text-13 text-muted">Who to chase today. A promise parks a tenant until their date, then brings them back if the money never came.</p>
        </div>
    </div>

    {{-- The council's number: how much of the arrears anyone has actually secured --}}
    <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="stat">
            <p class="text-12 font-bold uppercase tracking-[0.08em] text-muted">Needs chasing</p>
            <p class="mt-1 text-24 font-bold tabular-nums text-danger-fg">{{ $summary['unsecured']->format() }}</p>
            {{-- The money and the count measure different things: everything without a
                 live promise, vs. who is actually due a call today. Say both plainly. --}}
            <p class="text-12 text-muted">Not secured by a promise · {{ $summary['needs_attention'] }} on today's worklist</p>
        </div>
        <div class="stat">
            <p class="text-12 font-bold uppercase tracking-[0.08em] text-muted">Promised</p>
            <p class="mt-1 text-24 font-bold tabular-nums text-ink">{{ $summary['promised']->format() }}</p>
            <p class="text-12 text-muted">Secured against a date</p>
        </div>
        <div class="stat">
            <p class="text-12 font-bold uppercase tracking-[0.08em] text-muted">Total overdue</p>
            <p class="mt-1 text-24 font-bold tabular-nums text-ink">{{ $summary['total']->format() }}</p>
            <p class="text-12 text-muted">Across all tenants</p>
        </div>
    </div>

    <div class="mb-2 flex items-center gap-1 border-b border-line-2">
        <button wire:click="$set('tab', 'attention')" class="tab {{ $tab === 'attention' ? 'tab-active' : '' }}">Needs attention</button>
        <button wire:click="$set('tab', 'promised')" class="tab {{ $tab === 'promised' ? 'tab-active' : '' }}">Promised</button>
        <button wire:click="$set('tab', 'all')" class="tab {{ $tab === 'all' ? 'tab-active' : '' }}">All in arrears</button>
        <span class="ml-auto pb-2 text-12 text-muted">{{ $rows->count() }} tenant{{ $rows->count() === 1 ? '' : 's' }}</span>
    </div>

    <div class="overflow-hidden rounded-md border border-line bg-surface shadow-card">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[860px] text-13">
                <thead>
                    <tr class="border-b border-line">
                        <th class="th text-left">Tenant</th>
                        <th class="th text-left">Status</th>
                        <th class="th text-right">Outstanding</th>
                        <th class="th text-right">Oldest</th>
                        <th class="th text-left">Last contact</th>
                        <th class="th w-40"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-2">
                    @forelse ($rows as $row)
                        @php $tenant = $row['tenant']; @endphp
                        <tr class="hover:bg-hover">
                            <td class="td">
                                <p class="font-medium text-ink">{{ $tenant->name }}</p>
                                <p class="text-12 text-muted">{{ $tenant->mobile ?: 'No mobile on file' }} · {{ $row['invoice_count'] }} unpaid invoice{{ $row['invoice_count'] === 1 ? '' : 's' }}</p>
                            </td>
                            <td class="td">
                                <span class="loz {{ $row['state']->lozenge() }}">{{ $row['state']->label() }}</span>
                                @if ($row['last_contact']?->isPromise() && $row['promise_outcome'] !== \App\Enums\PromiseOutcome::None)
                                    <span class="block text-12 text-muted">
                                        {{ $row['last_contact']->promisedTarget()->format() }} by {{ $row['last_contact']->promised_on->format('j M Y') }}
                                    </span>
                                @endif
                            </td>
                            <td class="td text-right font-medium tabular-nums text-danger-fg">{{ $row['outstanding']->format() }}</td>
                            <td class="td text-right tabular-nums text-subtle">{{ $row['days_overdue'] }} d</td>
                            <td class="td">
                                @if ($row['last_contact'])
                                    <p class="text-ink">{{ $row['last_contact']->contacted_on->format('j M Y') }} · {{ $row['last_contact']->channel->label() }}</p>
                                    <p class="max-w-[22rem] truncate text-12 text-muted">{{ $row['last_contact']->note }}</p>
                                @else
                                    <span class="text-muted">Never contacted</span>
                                @endif
                            </td>
                            <td class="td">
                                <span class="flex items-center justify-end gap-1">
                                    @if ($row['last_contact'])
                                        <button wire:click="showHistory({{ $tenant->id }})" class="icon-btn" title="Contact history" aria-label="Contact history for {{ $tenant->name }}">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/></svg>
                                        </button>
                                    @endif
                                    <button wire:click="startContact({{ $tenant->id }})" class="btn-subtle h-7 px-2.5 text-12">Log contact</button>
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-13 text-muted">
                                @if ($tab === 'attention')
                                    Nothing to chase — every tenant in arrears has a live promise.
                                @elseif ($tab === 'promised')
                                    No outstanding promises right now.
                                @else
                                    No tenant is in arrears.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ============ Log a contact ============ --}}
    @if ($contactTenant)
        <x-modal title="Log a follow-up" :description="$contactTenant->name" close="closeOverlays">
            <div class="space-y-4 px-5 py-4">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="fl-req">How</label>
                        <x-select wire:model="contact_channel" class="mt-1"
                            :options="collect($channels)->mapWithKeys(fn ($c) => [$c->value => $c->label()])" />
                    </div>
                    <div>
                        <label class="fl">Promised to pay by</label>
                        <input type="date" wire:model="contact_promised_on" class="input mt-1">
                        @error('contact_promised_on') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="fl-req">What was said</label>
                    <textarea wire:model="contact_note" rows="3" class="input mt-1"
                        placeholder="e.g. Called — away on the atoll, promises to settle in full on the 25th."></textarea>
                    @error('contact_note') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="fl">Amount promised (MVR, optional)</label>
                    <input type="text" wire:model="contact_promised_amount" placeholder="Leave blank for the full balance"
                        class="input mt-1 text-right tabular-nums">
                    @error('contact_promised_amount') <p class="mt-1 text-13 text-danger-fg">{{ $message }}</p> @enderror
                    <p class="mt-1 text-12 text-muted">
                        With a date set, this tenant drops off the worklist until then — and comes back at
                        the top if the payments do not arrive.
                    </p>
                </div>
            </div>
            <div class="flex items-center justify-end gap-2 rounded-b-xl border-t border-line-2 bg-sunken px-5 py-3.5">
                <button wire:click="closeOverlays" class="btn-subtle">Cancel</button>
                <button wire:click="saveContact" class="btn-primary">Log follow-up</button>
            </div>
        </x-modal>
    @endif

    {{-- ============ Contact history ============ --}}
    @if ($historyTenant)
        <x-modal title="Contact history" :description="$historyTenant->name" close="closeOverlays">
            <div class="max-h-[60vh] space-y-2.5 overflow-y-auto px-5 py-4">
                @foreach ($history as $contact)
                    <div class="rounded border border-line bg-surface p-3.5 shadow-xs">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-13 font-semibold text-ink">{{ $contact->contacted_on->format('j M Y') }}</span>
                            <span class="text-12 text-muted">{{ $contact->channel->label() }}</span>
                            @php $outcome = $outcomes[$contact->id] ?? \App\Enums\PromiseOutcome::None; @endphp
                            @if ($outcome !== \App\Enums\PromiseOutcome::None)
                                <span class="loz {{ $outcome->lozenge() }}">{{ $outcome->label() }}</span>
                            @endif
                            <span class="ml-auto text-12 text-muted">{{ $contact->recordedBy?->name ?? 'System' }}</span>
                        </div>
                        <p class="mt-1.5 text-13 text-ink">{{ $contact->note }}</p>
                        <p class="mt-1 text-12 text-muted">
                            Owed {{ \App\Support\Money::fromLaari($contact->outstanding_at_contact_laari)->format() }} at the time
                            @if ($contact->isPromise())
                                · promised {{ $contact->promisedTarget()->format() }} by {{ $contact->promised_on->format('j M Y') }}
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
            <div class="flex items-center justify-end gap-2 rounded-b-xl border-t border-line-2 bg-sunken px-5 py-3.5">
                <button wire:click="closeOverlays" class="btn-subtle">Close</button>
            </div>
        </x-modal>
    @endif
</div>
