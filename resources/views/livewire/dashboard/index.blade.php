<div>
    <x-toast />

    <nav class="mb-1.5 flex items-center gap-1.5 text-12 text-muted"><span>Council</span><span>/</span><span class="text-subtle">Revenue</span></nav>
    <div class="mb-5 flex items-end justify-between gap-4">
        <div>
            <h1 class="text-24 font-semibold text-ink">Dashboard</h1>
            <p class="mt-0.5 text-13 text-subtle">Overview of leases, billing and arrears — {{ $today->format('F Y') }}</p>
        </div>
        <div class="flex items-center gap-2">
            @can('view reports')
                <a href="{{ route('reports.arrears') }}" class="btn-subtle">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M7 15l3-4 3 2 4-6"/></svg>
                    Reports
                </a>
            @endcan
            @can('create', \App\Models\Lease::class)
                <a href="{{ route('leases.index', ['create' => 1]) }}" class="btn-primary">Create lease</a>
            @endcan
        </div>
    </div>

    {{-- stat cards (FR-RPT-01) --}}
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-5">
        <div class="stat">
            <p class="text-12 text-muted">Active leases</p>
            <p class="mt-1 text-20 font-semibold tabular-nums text-ink">{{ number_format($metrics['active_leases']) }}</p>
            <p class="mt-1.5 text-11 text-muted">across the register</p>
        </div>
        <div class="stat">
            <p class="text-12 text-muted">Billed this month</p>
            <p class="mt-1 text-20 font-semibold tabular-nums text-ink">{{ \App\Support\Money::fromLaari($metrics['billed_laari'])->format() }}</p>
            <p class="mt-1.5 text-11 text-muted">{{ $metrics['billed_invoices'] }} invoice{{ $metrics['billed_invoices'] === 1 ? '' : 's' }} issued</p>
        </div>
        <div class="stat">
            <p class="text-12 text-muted">Collected this month</p>
            <p class="mt-1 text-20 font-semibold tabular-nums text-ink">{{ \App\Support\Money::fromLaari($metrics['collected_laari'])->format() }}</p>
            <p class="mt-1.5 text-11 text-muted">
                {{ $metrics['billed_laari'] > 0 ? round($metrics['collected_laari'] / $metrics['billed_laari'] * 100).'% of billed' : '—' }}
            </p>
        </div>
        <div class="stat ring-1 ring-danger-bg">
            <p class="text-12 text-muted">Arrears</p>
            <p class="mt-1 text-20 font-semibold tabular-nums text-danger-fg">{{ \App\Support\Money::fromLaari($metrics['arrears_laari'])->format() }}</p>
            <p class="mt-1.5 text-11 text-danger-fg">{{ $metrics['overdue_invoices'] }} overdue invoice{{ $metrics['overdue_invoices'] === 1 ? '' : 's' }}</p>
        </div>
        <div class="stat">
            <p class="text-12 text-muted">Fines outstanding</p>
            <p class="mt-1 text-20 font-semibold tabular-nums text-ink">{{ \App\Support\Money::fromLaari($metrics['fines_outstanding_laari'])->format() }}</p>
            <p class="mt-1.5 text-11 text-muted">accruing daily</p>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- needs attention --}}
        <div class="rounded-md border border-line bg-surface shadow-card lg:col-span-2">
            <div class="flex h-12 items-center justify-between border-b border-line-2 px-4">
                <h2 class="text-14 font-semibold text-ink">Needs attention · overdue</h2>
                @can('view reports')
                    <a href="{{ route('reports.arrears') }}" class="text-13 text-brand-600 hover:underline">View all arrears</a>
                @endcan
            </div>
            @if ($needsAttention->isEmpty())
                <p class="px-4 py-8 text-center text-13 text-muted">No overdue invoices — everything's up to date.</p>
            @else
                <table class="w-full text-13">
                    <tbody class="divide-y divide-line-2">
                        @foreach ($needsAttention as $row)
                            @php $invoice = $row['invoice']; @endphp
                            <tr class="hover:bg-hover">
                                <td class="py-2.5 pl-4 pr-2">
                                    <p class="font-medium text-ink">{{ $invoice->lease->property->name }}</p>
                                    <p class="text-12 text-muted">{{ $invoice->lease->agreement_number }} · {{ $invoice->lease->tenant->name }}</p>
                                </td>
                                <td class="px-2"><span class="loz loz-danger">Overdue {{ $row['days_overdue'] }}d</span></td>
                                <td class="px-2 text-right font-medium tabular-nums">{{ \App\Support\Money::fromLaari($row['outstanding_laari'])->format() }}</td>
                                <td class="w-10 py-2.5 pl-2 pr-4 text-right">
                                    @can(\App\Enums\Permission::ManageLeases->value)
                                        <a href="{{ route('leases.index', ['q' => $invoice->lease->agreement_number]) }}" class="text-muted hover:text-ink">›</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- upcoming expiries (FR-RPT-03) --}}
        <div class="rounded-md border border-line bg-surface shadow-card">
            <div class="flex h-12 items-center border-b border-line-2 px-4">
                <h2 class="text-14 font-semibold text-ink">Upcoming expiries</h2>
            </div>
            @if ($upcoming->isEmpty())
                <p class="px-4 py-8 text-center text-13 text-muted">Nothing expiring in the next 90 days.</p>
            @else
                <ul class="divide-y divide-line-2 text-13">
                    @foreach ($upcoming as $item)
                        <li class="flex items-start gap-3 px-4 py-3">
                            <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded {{ $item['days'] <= 30 && $item['kind'] === 'expiry' ? 'bg-warning-bg text-warning-fg' : 'bg-info-bg text-info-fg' }}">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                            </span>
                            <div>
                                <p class="font-medium text-ink">{{ $item['lease']->property->name }}</p>
                                <p class="text-12 text-muted">{{ $item['label'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
