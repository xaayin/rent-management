<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Arrears report — {{ $today->format('j F Y') }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #172B4D; font-size: 12px; padding: 40px; }
        h1 { font-size: 20px; margin-bottom: 2px; }
        .muted { color: #626F86; }
        .header { display: flex; justify-content: space-between; margin-bottom: 24px; border-bottom: 2px solid #172B4D; padding-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #626F86; border-bottom: 1px solid #DFE1E6; padding: 6px 8px; }
        td { padding: 7px 8px; border-bottom: 1px solid #EBECF0; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .total-row td { border-top: 2px solid #172B4D; border-bottom: none; font-weight: 700; }
        .danger { color: #AE2E24; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>{{ config('app.name') }}</h1>
            <p class="muted">Arrears / aging report</p>
        </div>
        <div style="text-align:right">
            <p><strong>As of {{ $today->format('j F Y') }}</strong></p>
            <p class="muted">{{ $rows->count() }} overdue invoice(s)</p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Invoice</th><th>Tenant</th><th>Property</th><th>Due date</th>
                <th class="num">Days overdue</th><th class="num">Current fine</th><th class="num">Outstanding</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['invoice']->number }}</td>
                    <td>{{ $row['invoice']->lease->tenant->name }}</td>
                    <td>{{ $row['invoice']->lease->property->name }}</td>
                    <td>{{ $row['invoice']->due_date->format('j M Y') }}</td>
                    <td class="num danger">{{ $row['days_overdue'] }}</td>
                    <td class="num">{{ \App\Support\Money::fromLaari($row['outstanding_fine_laari'])->format() }}</td>
                    <td class="num">{{ \App\Support\Money::fromLaari($row['outstanding_laari'])->format() }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="5">Total</td>
                <td class="num">{{ \App\Support\Money::fromLaari((int) $rows->sum('outstanding_fine_laari'))->format() }}</td>
                <td class="num danger">{{ \App\Support\Money::fromLaari((int) $rows->sum('outstanding_laari'))->format() }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
