<div>
    <nav class="mb-2 text-[11px] font-bold uppercase tracking-[0.08em] text-muted">Registry / Tenants / Statement</nav>
    <div class="mb-6 flex items-start justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-ink">Account statement</h1>
            <p class="text-[13px] text-muted">
                {{ $tenant->name }}
                @if ($tenant->registryNumber()) · {{ $tenant->registryNumber() }} @endif
                · consolidated across {{ $tenant->leases()->count() }} lease(s)
            </p>
        </div>
        <div class="rounded-md border border-line bg-surface px-4 py-3 text-right shadow-card">
            <p class="text-[11px] font-bold uppercase tracking-[0.08em] text-muted">Balance due</p>
            <p class="font-display text-24 font-extrabold tracking-[-0.02em] tabular-nums {{ $balance->isPositive() ? 'text-danger-fg' : 'text-success-fg' }}">{{ $balance->format() }}</p>
        </div>
    </div>

    <div class="overflow-x-auto rounded-md border border-line bg-surface shadow-card">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-line">
                    <th class="th text-left">Date</th>
                    <th class="th text-left">Description</th>
                    <th class="th text-right">Debit</th>
                    <th class="th text-right">Credit</th>
                    <th class="th text-right">Balance</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $entry)
                    <tr class="border-b border-line-2 last:border-0 hover:bg-hover">
                        <td class="px-4 py-3 tabular-nums text-subtle whitespace-nowrap">{{ $entry['date'] }}</td>
                        <td class="px-4 py-3">
                            <p class="font-medium text-ink">{{ $entry['label'] }}</p>
                            @if ($entry['detail'] !== '')
                                <p class="text-[12px] text-muted">{{ $entry['detail'] }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink">
                            {{ $entry['debit'] !== 0 ? \App\Support\Money::fromLaari($entry['debit'])->format() : '' }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-success-fg">
                            {{ $entry['credit'] !== 0 ? \App\Support\Money::fromLaari($entry['credit'])->format() : '' }}
                        </td>
                        <td class="px-4 py-3 text-right font-medium tabular-nums text-ink">{{ \App\Support\Money::fromLaari($entry['balance'])->format() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-[13px] text-muted">No transactions yet for this tenant.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
