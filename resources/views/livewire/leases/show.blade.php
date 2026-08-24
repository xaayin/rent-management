<div wire:keydown.escape.window="closeOverlays">
    <x-toast />

    {{-- ============ Identity header ============ --}}
    <nav class="mb-1.5 flex items-center gap-1.5 text-12 text-muted">
        <span>Council</span><span>/</span>
        <a href="{{ route('leases.index') }}" class="hover:underline">Leases</a>
        <span>/</span><span class="text-subtle">{{ $lease->agreement_number }}</span>
    </nav>

    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2.5">
                <h1 class="text-24 font-semibold text-ink">{{ $lease->property->name }}</h1>
                <span class="loz {{ match ($lease->status) {
                    \App\Enums\LeaseStatus::Active => 'loz-success',
                    \App\Enums\LeaseStatus::Terminated => 'loz-danger',
                    \App\Enums\LeaseStatus::Expired => 'loz-warning',
                    default => 'loz-neutral',
                } }}">{{ $lease->status->label() }}</span>
            </div>
            <p class="mt-1 text-13 text-muted">
                <span class="text-subtle">{{ $lease->agreement_number }}</span> ·
                {{ $lease->tenant->name }} · {{ $lease->tenant->type->label() }}
                @if ($lease->tenant->registryNumber()) · {{ $lease->tenant->registryNumber() }} @endif
                @if ($lease->tenant->mobile) · {{ $lease->tenant->mobile }} @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('create', \App\Models\Payment::class)
                @if ($summary['unpaid_count'] > 0)
                    <button wire:click="startPayment({{ $summary['invoices']->first()['invoice']->id }})" class="btn-primary">Record payment</button>
                @endif
            @endcan
            @can('generate', \App\Models\Invoice::class)
                <a href="{{ route('invoices.index', ['createFor' => $lease->id]) }}" class="btn-subtle">Create invoice</a>
            @endcan
            @can('update', $lease)
                <a href="{{ route('leases.index', ['edit' => $lease->id]) }}" class="btn-subtle">Edit lease</a>
            @endcan
        </div>
    </div>

    {{-- ============ Money band — what is owed right now ============ --}}
    <div class="mb-5 overflow-hidden rounded-md border {{ $summary['settled'] ? 'border-success-bg bg-success-bg/30' : 'border-danger-bg bg-danger-bg/25' }} shadow-card">
        <div class="flex flex-wrap items-center justify-between gap-6 px-5 py-4">
            <div>
                <p class="text-11 font-bold uppercase tracking-[0.08em] {{ $summary['settled'] ? 'text-success-fg' : 'text-danger-fg' }}">
                    {{ $summary['settled'] ? 'Up to date' : 'Due today' }}
                </p>
                <p class="mt-1 text-[32px] font-bold leading-none tabular-nums {{ $summary['settled'] ? 'text-success-fg' : 'text-danger-fg' }}">
                    {{ $summary['total']->format() }}
                </p>
                @unless ($summary['settled'])
                    <p class="mt-1.5 text-12 text-subtle">
                        {{ $summary['unpaid_count'] }} unpaid invoice{{ $summary['unpaid_count'] === 1 ? '' : 's' }}
                        @if ($summary['days_overdue'] > 0) · oldest {{ $summary['days_overdue'] }} days overdue @endif
                    </p>
                @endunless
            </div>

            @unless ($summary['settled'])
                {{-- The split, so the fine is never a mystery component of a total --}}
                <div class="flex gap-8 text-13">
                    <div>
                        <p class="text-12 text-muted">Rent &amp; charges</p>
                        <p class="mt-0.5 font-semibold tabular-nums text-ink">{{ $summary['principal']->format() }}</p>
                    </div>
                    <div>
                        <p class="text-12 text-muted">Fine as of {{ $summary['as_of']->format('j M') }}</p>
                        <p class="mt-0.5 font-semibold tabular-nums text-ink">{{ $summary['fine']->format() }}</p>
                    </div>
                </div>
            @else
                <p class="text-13 text-subtle">Nothing outstanding on this lease.</p>
            @endunless
        </div>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        {{-- ============ MAIN: the money and its history ============ --}}
        <div class="space-y-5 lg:col-span-2">

            {{-- Outstanding invoices — the actionable ones --}}
            @if ($summary['unpaid_count'] > 0)
                <div class="overflow-hidden rounded-md border border-line bg-surface shadow-card">
                    <p class="border-b border-line-2 px-4 py-3 font-display text-16 font-bold tracking-[-0.01em] text-ink">Outstanding invoices</p>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-13">
                            <thead>
                                <tr class="border-b border-line-2">
                                    <th class="th text-left">Invoice</th>
                                    <th class="th text-left">Due</th>
                                    <th class="th text-right">Rent &amp; charges</th>
                                    <th class="th text-right">Fine today</th>
                                    <th class="th text-right">Total</th>
                                    <th class="th w-24"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line-2">
                                @foreach ($summary['invoices'] as $row)
                                    <tr class="hover:bg-hover">
                                        <td class="td">
                                            <span class="font-medium text-ink">{{ $row['invoice']->number }}</span>
                                            @if ($row['invoice']->kind === \App\Enums\InvoiceKind::Csr)
                                                <span class="loz loz-discovery ml-1">CSR</span>
                                            @endif
                                            <span class="block text-12 text-muted">{{ $row['invoice']->periodLabel() }}</span>
                                        </td>
                                        <td class="td">
                                            <span class="text-subtle">{{ $row['due_date']->format('j M Y') }}</span>
                                            @if ($row['days_overdue'] > 0)
                                                <span class="block text-12 text-danger-fg">{{ $row['days_overdue'] }} days late</span>
                                            @endif
                                        </td>
                                        <td class="td text-right tabular-nums">{{ $row['principal']->format() }}</td>
                                        <td class="td text-right tabular-nums {{ $row['fine']->isPositive() ? 'text-danger-fg' : 'text-muted' }}">{{ $row['fine']->format() }}</td>
                                        <td class="td text-right font-semibold tabular-nums text-ink">{{ $row['total']->format() }}</td>
                                        <td class="td text-right">
                                            @can('create', \App\Models\Payment::class)
                                                <button wire:click="startPayment({{ $row['invoice']->id }})" class="btn-subtle h-7 px-2.5 text-12">Pay</button>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Full invoice history --}}
            <div class="overflow-hidden rounded-md border border-line bg-surface shadow-card">
                <div class="flex items-center justify-between border-b border-line-2 px-4 py-3">
                    <p class="font-display text-16 font-bold tracking-[-0.01em] text-ink">Invoice history</p>
                    <span class="text-12 text-muted">{{ $invoiceHistory->count() }} of {{ $invoiceTotal }}</span>
                </div>
                @if ($invoiceHistory->isEmpty())
                    <p class="px-4 py-8 text-center text-13 text-muted">No invoices raised on this lease yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[600px] text-13">
                            <thead>
                                <tr class="border-b border-line-2">
                                    <th class="th text-left">Invoice</th>
                                    <th class="th text-left">Period</th>
                                    <th class="th text-left">Status</th>
                                    <th class="th text-right">Total</th>
                                    <th class="th text-right">Paid</th>
                                    <th class="th w-10"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line-2">
                                @foreach ($invoiceHistory as $invoice)
                                    <tr class="hover:bg-hover">
                                        <td class="td font-medium text-ink">
                                            {{ $invoice->number }}
                                            @if ($invoice->kind === \App\Enums\InvoiceKind::Csr)
                                                <span class="loz loz-discovery ml-1">CSR</span>
                                            @endif
                                        </td>
                                        <td class="td text-subtle">{{ $invoice->periodLabel() }}</td>
                                        <td class="td">
                                            <span class="loz {{ match ($invoice->status) {
                                                \App\Enums\InvoiceStatus::Paid => 'loz-success',
                                                \App\Enums\InvoiceStatus::PartlyPaid => 'loz-warning',
                                                \App\Enums\InvoiceStatus::Overdue => 'loz-danger',
                                                \App\Enums\InvoiceStatus::Cancelled => 'loz-neutral',
                                                default => 'loz-info',
                                            } }}">{{ $invoice->status->label() }}</span>
                                        </td>
                                        <td class="td text-right tabular-nums">{{ $invoice->total()->format() }}</td>
                                        <td class="td text-right tabular-nums text-subtle">{{ \App\Support\Money::fromLaari((int) $invoice->paid_laari)->format() }}</td>
                                        <td class="td text-right">
                                            @can('view reports')
                                                <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" class="icon-btn" title="Invoice PDF" aria-label="Invoice {{ $invoice->number }} PDF">
                                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                                                </a>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($invoiceHistory->count() < $invoiceTotal)
                        <div class="border-t border-line-2 px-4 py-2.5 text-center">
                            <button wire:click="showMoreHistory" class="text-12 font-semibold text-brand-600 hover:underline">Show more</button>
                        </div>
                    @endif
                @endif
            </div>

            {{-- Payments & receipts — beside the invoices, never behind a tab --}}
            <div class="overflow-hidden rounded-md border border-line bg-surface shadow-card">
                <p class="border-b border-line-2 px-4 py-3 font-display text-16 font-bold tracking-[-0.01em] text-ink">Payments &amp; receipts</p>
                @if ($payments->isEmpty())
                    <p class="px-4 py-8 text-center text-13 text-muted">No payments recorded against this lease.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[620px] text-13">
                            <thead>
                                <tr class="border-b border-line-2">
                                    <th class="th text-left">Receipt</th>
                                    <th class="th text-left">Date</th>
                                    <th class="th text-left">Against</th>
                                    <th class="th text-right">Rent</th>
                                    <th class="th text-right">Fine</th>
                                    <th class="th text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line-2">
                                @foreach ($payments as $payment)
                                    <tr class="hover:bg-hover {{ $payment->isReversal() ? 'bg-danger-bg/15' : '' }}">
                                        <td class="td">
                                            @if ($payment->isReversal())
                                                <span class="font-medium text-danger-fg">Reversal</span>
                                                <span class="block text-12 text-muted">of {{ $payment->reference }}</span>
                                            @else
                                                <span class="font-medium text-ink">{{ $payment->receipt_number }}</span>
                                                @if ($payment->isReversed())<span class="loz loz-danger ml-1">Reversed</span>@endif
                                                <span class="block text-12 text-muted">{{ $payment->method->label() }}</span>
                                            @endif
                                        </td>
                                        <td class="td text-subtle">{{ $payment->payment_date->format('j M Y') }}</td>
                                        <td class="td text-subtle">{{ $payment->invoice->number }}</td>
                                        <td class="td text-right tabular-nums text-subtle">{{ $payment->principalAllocated()->format() }}</td>
                                        <td class="td text-right tabular-nums text-subtle">{{ $payment->fineAllocated()->format() }}</td>
                                        <td class="td text-right font-medium tabular-nums {{ $payment->isReversal() ? 'text-danger-fg' : 'text-ink' }}">{{ $payment->amount()->format() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- ============ RAIL: reference, ordered by how often it changes ============ --}}
        <div class="space-y-5">

            {{-- Terms --}}
            <div class="rounded-md border border-line bg-surface p-4 shadow-card">
                <p class="mb-3 font-display text-16 font-bold tracking-[-0.01em] text-ink">Lease terms</p>
                <dl class="space-y-2 text-13">
                    @foreach ([
                        'Monthly rent' => $lease->monthlyRent()->format(),
                        'Rent basis' => $lease->rent_basis->label(),
                        'Due day' => 'Day '.$lease->due_day.' of the month',
                        'Grace period' => $lease->grace_months.' month'.($lease->grace_months === 1 ? '' : 's'),
                        'Rent starts' => $lease->rent_start_date->format('j M Y'),
                        'Expires' => $lease->expiry_date->format('j M Y'),
                    ] as $label => $value)
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted">{{ $label }}</dt>
                            <dd class="text-right text-ink">{{ $value }}</dd>
                        </div>
                    @endforeach
                    @if ($lease->hasCsr())
                        <div class="flex justify-between gap-4 border-t border-line-2 pt-2">
                            <dt class="text-muted">CSR</dt>
                            <dd class="text-right text-ink">{{ $lease->csrAnnualAmount()->format() }}/year</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted">CSR invoicing</dt>
                            <dd class="text-right text-ink">{{ $lease->csr_billing->label() }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- Fine rule --}}
            <div class="rounded-md border border-line bg-surface p-4 shadow-card">
                <div class="mb-2.5 flex items-center justify-between gap-2">
                    <p class="font-display text-16 font-bold tracking-[-0.01em] text-ink">Fine rule</p>
                    @can('configureFineRule', $lease)
                        <a href="{{ route('leases.index', ['fines' => $lease->id]) }}" class="text-12 font-semibold text-brand-600 hover:underline">Schedule</a>
                    @endcan
                </div>
                @if ($rule)
                    <p class="text-13 text-ink">{{ $rule->summary() }}</p>
                    <p class="mt-1 text-12 text-muted">
                        {{ $rule->periodLabel() }} · base {{ $rule->base->label() }} · {{ $rule->allowance_days }}-day allowance
                    </p>
                    @if ($summary['fine']->isPositive())
                        <div class="mt-2.5 flex justify-between border-t border-line-2 pt-2.5 text-13">
                            <span class="font-semibold text-danger-fg">Accrued to {{ $summary['as_of']->format('j M') }}</span>
                            <span class="font-bold tabular-nums text-danger-fg">{{ $summary['fine']->format() }}</span>
                        </div>
                    @endif
                @else
                    <p class="text-13 text-muted">No fine rule in force — no fines accrue.</p>
                @endif
            </div>

            {{-- Follow-up (R2) --}}
            <div class="rounded-md border border-line bg-surface p-4 shadow-card">
                <div class="mb-2.5 flex items-center justify-between gap-2">
                    <p class="font-display text-16 font-bold tracking-[-0.01em] text-ink">Collection</p>
                    @can(\App\Enums\Permission::RecordPayments->value)
                        <a href="{{ route('follow-ups.index') }}" class="text-12 font-semibold text-brand-600 hover:underline">Follow-ups</a>
                    @endcan
                </div>
                @if ($lastContact)
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-13 font-semibold text-ink">{{ $lastContact->contacted_on->format('j M Y') }}</span>
                        <span class="text-12 text-muted">{{ $lastContact->channel->label() }}</span>
                        @if ($followUpOutcome && $followUpOutcome !== \App\Enums\PromiseOutcome::None)
                            <span class="loz {{ $followUpOutcome->lozenge() }}">{{ $followUpOutcome->label() }}</span>
                        @endif
                    </div>
                    <p class="mt-1.5 text-13 text-subtle">{{ $lastContact->note }}</p>
                @else
                    <p class="text-13 text-muted">No collection contact logged for this tenant.</p>
                @endif
            </div>

            {{-- Tenant --}}
            <div class="rounded-md border border-line bg-surface p-4 shadow-card">
                <p class="mb-2.5 font-display text-16 font-bold tracking-[-0.01em] text-ink">Tenant</p>
                <p class="text-13 font-medium text-ink">{{ $lease->tenant->name }}</p>
                <p class="mt-0.5 text-12 text-muted">{{ $lease->tenant->mobile ?: 'No mobile on file' }}</p>
                <div class="mt-2.5 flex justify-between border-t border-line-2 pt-2.5 text-13">
                    <span class="text-muted">Balance, all leases</span>
                    <span class="font-semibold tabular-nums text-ink">{{ $tenantBalance->format() }}</span>
                </div>
            </div>

            {{-- Termination state, when there is one --}}
            @if ($terminationRequest && $terminationRequest->status->value === 'pending')
                <div class="rounded-md border border-warning-bg bg-warning-bg/40 p-4">
                    <p class="text-12 font-bold uppercase tracking-[0.08em] text-warning-fg">Termination awaiting approval</p>
                    <p class="mt-1 text-13 text-ink">{{ $terminationRequest->reason }}</p>
                </div>
            @endif

            {{-- Activity --}}
            <div class="rounded-md border border-line bg-surface p-4 shadow-card">
                <p class="mb-2.5 font-display text-16 font-bold tracking-[-0.01em] text-ink">Activity</p>
                @if ($activity->isEmpty())
                    <p class="text-13 text-muted">Nothing recorded yet.</p>
                @else
                    <ol class="space-y-2.5">
                        @foreach ($activity as $entry)
                            <li class="text-13">
                                <p class="text-ink">{{ ucfirst($entry->description) }}</p>
                                <p class="text-12 text-muted">{{ $entry->created_at->format('j M Y, H:i') }}{{ $entry->causer ? ' · '.$entry->causer->name : '' }}</p>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </div>
    </div>

    {{-- Record payment reuses the shared surface, as an overlay on this page --}}
    @include('livewire.partials.payment-modal', [
        'unpaidChoices' => $summary['invoices']->mapWithKeys(fn ($row) => [
            $row['invoice']->id => $row['invoice']->number.' — '.$row['invoice']->periodLabel().' ('.$row['total']->format().' due)',
        ]),
    ])
</div>
