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

    {{-- stat cards (FR-RPT-01, council StatCard) --}}
    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-5">
        <div class="stat">
            <p class="flex items-center gap-2.5">
                <span class="grid h-[34px] w-[34px] shrink-0 place-items-center rounded bg-brand-50 text-brand-500">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h6"/></svg>
                </span>
                <span class="text-13 font-semibold text-subtle">Active leases</span>
            </p>
            <p class="mt-3 font-display text-24 font-extrabold tracking-[-0.02em] tabular-nums text-ink">{{ number_format($metrics['active_leases']) }}</p>
            <p class="mt-1.5 text-12 text-muted">across the register</p>
        </div>
        <div class="stat">
            <p class="flex items-center gap-2.5">
                <span class="grid h-[34px] w-[34px] shrink-0 place-items-center rounded bg-brand-50 text-brand-500">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M8 7h8M8 11h8M8 15h5"/></svg>
                </span>
                <span class="text-13 font-semibold text-subtle">Billed this month</span>
            </p>
            <p class="mt-3 font-display text-24 font-extrabold tracking-[-0.02em] tabular-nums text-ink">{{ \App\Support\Money::fromLaari($metrics['billed_laari'])->format() }}</p>
            <p class="mt-1.5 text-12 text-muted">{{ $metrics['billed_invoices'] }} invoice{{ $metrics['billed_invoices'] === 1 ? '' : 's' }} issued</p>
        </div>
        <div class="stat">
            <p class="flex items-center gap-2.5">
                <span class="grid h-[34px] w-[34px] shrink-0 place-items-center rounded bg-brand-50 text-brand-500">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                </span>
                <span class="text-13 font-semibold text-subtle">Collected this month</span>
            </p>
            <p class="mt-3 font-display text-24 font-extrabold tracking-[-0.02em] tabular-nums text-ink">{{ \App\Support\Money::fromLaari($metrics['collected_laari'])->format() }}</p>
            <p class="mt-1.5 text-12 text-muted">
                {{ $metrics['billed_laari'] > 0 ? round($metrics['collected_laari'] / $metrics['billed_laari'] * 100).'% of billed' : '—' }}
            </p>
        </div>
        <div class="stat">
            <p class="flex items-center gap-2.5">
                <span class="grid h-[34px] w-[34px] shrink-0 place-items-center rounded bg-danger-bg text-danger-fg">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 17l-8.5-8.5-5 5L2 7"/><path d="M16 17h6v-6"/></svg>
                </span>
                <span class="text-13 font-semibold text-subtle">Arrears</span>
            </p>
            <p class="mt-3 font-display text-24 font-extrabold tracking-[-0.02em] tabular-nums text-danger-fg">{{ \App\Support\Money::fromLaari($metrics['arrears_laari'])->format() }}</p>
            <p class="mt-1.5 text-12 text-danger-fg">{{ $metrics['overdue_invoices'] }} overdue invoice{{ $metrics['overdue_invoices'] === 1 ? '' : 's' }}</p>
        </div>
        <div class="stat">
            <p class="flex items-center gap-2.5">
                <span class="grid h-[34px] w-[34px] shrink-0 place-items-center rounded bg-warning-bg text-warning-fg">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                </span>
                <span class="text-13 font-semibold text-subtle">Fines outstanding</span>
            </p>
            <p class="mt-3 font-display text-24 font-extrabold tracking-[-0.02em] tabular-nums text-ink">{{ \App\Support\Money::fromLaari($metrics['fines_outstanding_laari'])->format() }}</p>
            <p class="mt-1.5 text-12 text-muted">accruing daily</p>
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
