<div>
    <x-toast />

    <nav class="mb-1.5 flex items-center gap-1.5 text-12 text-muted"><span>Council</span><span>/</span><span class="text-subtle">Reports</span></nav>
    <div class="mb-4 flex items-end justify-between gap-4">
        <h1 class="text-24 font-semibold text-ink">Reports</h1>
        <div class="flex items-center gap-2">
            <a href="{{ route('reports.arrears.export', 'csv') }}" class="btn-subtle">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                Export CSV
            </a>
            <a href="{{ route('reports.arrears.export', 'pdf') }}" target="_blank" class="btn-subtle">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                Export PDF
            </a>
        </div>
    </div>

    <div class="mb-2 flex items-center gap-1 border-b border-line-2">
        <a href="{{ route('reports.arrears') }}" class="tab tab-active">Arrears</a>
        <a href="{{ route('reports.income') }}" class="tab">Income</a>
    </div>

    <div class="mb-3 flex items-center justify-between text-12 text-muted">
        <span>{{ $rows->count() }} overdue invoice{{ $rows->count() === 1 ? '' : 's' }}</span>
        <span>Outstanding <span class="font-semibold tabular-nums text-danger-fg">{{ $totalOutstanding->format() }}</span> · fines <span class="tabular-nums">{{ $totalFines->format() }}</span></span>
    </div>

    <div class="overflow-hidden rounded-md border border-line bg-surface shadow-card">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[820px] text-13">
                <thead>
                    <tr class="border-b border-line">
                        <th class="th text-left">Invoice</th>
                        <th class="th text-left">Tenant</th>
                        <th class="th text-left">Property</th>
                        <th class="th text-left">Due date</th>
                        <th class="th text-left">Overdue</th>
                        <th class="th text-right">Current fine</th>
                        <th class="th text-right">Outstanding</th>
                        <th class="th w-24"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-2">
                    @forelse ($rows as $row)
                        @php $invoice = $row['invoice']; @endphp
                        <tr class="hover:bg-hover">
                            <td class="td font-medium text-ink">{{ $invoice->number }}</td>
                            <td class="td">
                                <span class="flex items-center gap-2">
                                    <span class="ava {{ ['bg-brand-500', 'bg-discovery-fg', 'bg-success-fg', 'bg-warning-fg', 'bg-brand-600', 'bg-danger-fg'][$invoice->lease->tenant->id % 6] }}">
                                        {{ collect(explode(' ', $invoice->lease->tenant->name))->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('') }}
                                    </span>
                                    {{ $invoice->lease->tenant->name }}
                                </span>
                            </td>
                            <td class="td text-subtle">{{ $invoice->lease->property->name }}</td>
                            <td class="td tabular-nums text-subtle">{{ $invoice->due_date->format('j M Y') }}</td>
                            <td class="td"><span class="loz loz-danger">{{ $row['days_overdue'] }}d</span></td>
                            <td class="td text-right tabular-nums {{ $row['outstanding_fine_laari'] > 0 ? 'text-danger-fg' : 'text-subtle' }}">
                                {{ \App\Support\Money::fromLaari($row['outstanding_fine_laari'])->format() }}
                            </td>
                            <td class="td text-right font-medium tabular-nums">{{ \App\Support\Money::fromLaari($row['outstanding_laari'])->format() }}</td>
                            <td class="td text-right whitespace-nowrap">
                                @can(\App\Enums\Permission::IssueInvoices->value)
                                    <button wire:click="sendReminder({{ $invoice->id }})" class="btn-subtle h-7 px-2 text-12">Remind</button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-10 text-center text-13 text-muted">No overdue invoices — everything's up to date.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
