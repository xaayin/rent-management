<div>
    <x-toast />

    <nav class="mb-1.5 flex items-center gap-1.5 text-12 text-muted"><span>Council</span><span>/</span><span class="text-subtle">Reports</span></nav>
    <div class="mb-4 flex items-end justify-between gap-4">
        <h1 class="text-24 font-semibold text-ink">Reports</h1>
        <div class="flex items-center gap-2">
            <x-select wire:model.live="year" class="w-28"
                :options="collect($years)->mapWithKeys(fn ($y) => [$y => $y])" />
            <a href="{{ route('reports.income.export', ['format' => 'csv', 'year' => $year]) }}" class="btn-subtle">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                Export CSV
            </a>
            <a href="{{ route('reports.income.export', ['format' => 'pdf', 'year' => $year]) }}" target="_blank" class="btn-subtle">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                Export PDF
            </a>
        </div>
    </div>

    <div class="mb-4 flex items-center gap-1 border-b border-line-2">
        <a href="{{ route('reports.arrears') }}" class="tab">Arrears</a>
        <a href="{{ route('reports.income') }}" class="tab tab-active">Income</a>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- monthly billed vs collected --}}
        <div class="rounded-md border border-line bg-surface shadow-card lg:col-span-2">
            <div class="flex h-12 items-center justify-between border-b border-line-2 px-4">
                <h2 class="text-14 font-semibold text-ink">Billed vs collected — {{ $year }}</h2>
            </div>
            <table class="w-full text-13">
                <thead>
                    <tr class="border-b border-line">
                        <th class="th text-left">Month</th>
                        <th class="th text-right">Billed</th>
                        <th class="th text-right">Collected</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-2">
                    @foreach ($months as $row)
                        <tr class="hover:bg-hover {{ $row['billed_laari'] === 0 && $row['collected_laari'] === 0 ? 'text-muted' : '' }}">
                            <td class="td">{{ date('F', mktime(0, 0, 0, $row['month'], 1)) }}</td>
                            <td class="td text-right tabular-nums">{{ \App\Support\Money::fromLaari($row['billed_laari'])->format() }}</td>
                            <td class="td text-right tabular-nums">{{ \App\Support\Money::fromLaari($row['collected_laari'])->format() }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-t-2 border-line font-semibold">
                        <td class="td">Total</td>
                        <td class="td text-right tabular-nums">{{ \App\Support\Money::fromLaari($totalBilled)->format() }}</td>
                        <td class="td text-right tabular-nums">{{ \App\Support\Money::fromLaari($totalCollected)->format() }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- breakdowns --}}
        <div class="space-y-4">
            <div class="rounded-md border border-line bg-surface shadow-card">
                <div class="flex h-12 items-center border-b border-line-2 px-4">
                    <h2 class="text-14 font-semibold text-ink">Collected by property type</h2>
                </div>
                @if ($byPropertyType->isEmpty())
                    <p class="px-4 py-6 text-center text-13 text-muted">No collections in {{ $year }}.</p>
                @else
                    <ul class="divide-y divide-line-2 text-13">
                        @foreach ($byPropertyType as $row)
                            <li class="flex items-center justify-between px-4 py-2.5">
                                <span class="text-subtle">{{ \App\Enums\UsageType::from($row->group)->label() }}</span>
                                <span class="font-medium tabular-nums">{{ \App\Support\Money::fromLaari((int) $row->laari)->format() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="rounded-md border border-line bg-surface shadow-card">
                <div class="flex h-12 items-center border-b border-line-2 px-4">
                    <h2 class="text-14 font-semibold text-ink">Collected by tenant type</h2>
                </div>
                @if ($byTenantType->isEmpty())
                    <p class="px-4 py-6 text-center text-13 text-muted">No collections in {{ $year }}.</p>
                @else
                    <ul class="divide-y divide-line-2 text-13">
                        @foreach ($byTenantType as $row)
                            <li class="flex items-center justify-between px-4 py-2.5">
                                <span class="flex items-center gap-2">
                                    <span class="loz {{ $row->group === 'organisation' ? 'loz-discovery' : 'loz-info' }}">{{ \App\Enums\TenantType::from($row->group)->label() }}</span>
                                </span>
                                <span class="font-medium tabular-nums">{{ \App\Support\Money::fromLaari((int) $row->laari)->format() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
