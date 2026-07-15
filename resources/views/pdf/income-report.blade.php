<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Income report — {{ $year }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Noto Sans Thaana', ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #172B4D; font-size: 12px; padding: 40px; }
        h1 { font-size: 20px; margin-bottom: 2px; }
        h2 { font-size: 13px; margin: 22px 0 8px; }
        .muted { color: #626F86; }
        .header { display: flex; justify-content: space-between; margin-bottom: 24px; border-bottom: 2px solid #172B4D; padding-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #626F86; border-bottom: 1px solid #DFE1E6; padding: 6px 8px; }
        td { padding: 7px 8px; border-bottom: 1px solid #EBECF0; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .total-row td { border-top: 2px solid #172B4D; border-bottom: none; font-weight: 700; }
    </style>
    @include('pdf.partials.fonts')
</head>
<body>
    <div class="header">
        <div>
            <h1>{{ config('app.name') }}</h1>
            <p class="muted">Income report — billed vs collected</p>
        </div>
        <div style="text-align:right"><p><strong>{{ $year }}</strong></p></div>
    </div>

    <table>
        <thead><tr><th>Month</th><th class="num">Billed</th><th class="num">Collected</th></tr></thead>
        <tbody>
            @foreach ($months as $row)
                <tr>
                    <td>{{ date('F', mktime(0, 0, 0, $row['month'], 1)) }}</td>
                    <td class="num">{{ \App\Support\Money::fromLaari($row['billed_laari'])->format() }}</td>
                    <td class="num">{{ \App\Support\Money::fromLaari($row['collected_laari'])->format() }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td>Total</td>
                <td class="num">{{ \App\Support\Money::fromLaari((int) $months->sum('billed_laari'))->format() }}</td>
                <td class="num">{{ \App\Support\Money::fromLaari((int) $months->sum('collected_laari'))->format() }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Collected by property type</h2>
    <table>
        <tbody>
            @forelse ($byPropertyType as $row)
                <tr>
                    <td>{{ \App\Enums\UsageType::from($row->group)->label() }}</td>
                    <td class="num">{{ \App\Support\Money::fromLaari((int) $row->laari)->format() }}</td>
                </tr>
            @empty
                <tr><td class="muted">No collections in {{ $year }}.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Collected by tenant type</h2>
    <table>
        <tbody>
            @forelse ($byTenantType as $row)
                <tr>
                    <td>{{ \App\Enums\TenantType::from($row->group)->label() }}</td>
                    <td class="num">{{ \App\Support\Money::fromLaari((int) $row->laari)->format() }}</td>
                </tr>
            @empty
                <tr><td class="muted">No collections in {{ $year }}.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
