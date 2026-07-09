<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #172B4D; font-size: 13px; padding: 40px; }
        h1 { font-size: 22px; margin-bottom: 2px; }
        .muted { color: #626F86; }
        .header { display: flex; justify-content: space-between; margin-bottom: 28px; border-bottom: 2px solid #172B4D; padding-bottom: 16px; }
        .header .right { text-align: right; }
        .badge { display: inline-block; padding: 2px 8px; border: 1px solid #172B4D; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
        .parties { display: flex; gap: 40px; margin-bottom: 24px; }
        .parties h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #626F86; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #626F86; border-bottom: 1px solid #DFE1E6; padding: 6px 8px; }
        td { padding: 8px; border-bottom: 1px solid #EBECF0; vertical-align: top; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .total-row td { border-top: 2px solid #172B4D; border-bottom: none; font-weight: 700; font-size: 15px; }
        .breakdown { font-size: 12px; color: #626F86; margin-top: 4px; }
        .breakdown li { margin-left: 16px; }
        .footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid #DFE1E6; font-size: 12px; color: #626F86; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>{{ config('app.name') }}</h1>
            <p class="muted">Council Land &amp; Property Lease Management</p>
        </div>
        <div class="right">
            <h1>Invoice {{ $invoice->number }}</h1>
            <p class="muted">Period: {{ $invoice->period_start->format('j F Y') }} – {{ $invoice->period_end->format('j F Y') }}</p>
            <p class="muted">Due date: <strong>{{ $invoice->due_date->format('j F Y') }}</strong></p>
            <p style="margin-top:6px"><span class="badge">{{ $invoice->status->label() }}</span></p>
        </div>
    </div>

    <div class="parties">
        <div>
            <h2>Billed to</h2>
            <p><strong>{{ $invoice->lease->tenant->name }}</strong></p>
            @if ($invoice->lease->tenant->registryNumber())
                <p class="muted">{{ $invoice->lease->tenant->isIndividual() ? 'National ID' : 'Company reg. no.' }}: {{ $invoice->lease->tenant->registryNumber() }}</p>
            @endif
            @if ($invoice->lease->tenant->postal_address)
                <p class="muted">{{ $invoice->lease->tenant->postal_address }}</p>
            @endif
        </div>
        <div>
            <h2>Property</h2>
            <p><strong>{{ $invoice->lease->property->name }}</strong></p>
            <p class="muted">Land no. {{ $invoice->lease->property->land_number }} · {{ number_format($invoice->lease->property->size_sqft) }} ft²</p>
            <p class="muted">Agreement {{ $invoice->lease->agreement_number }}</p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lineItems as $line)
                <tr>
                    <td>
                        {{ $line->description }}
                        @if ($line->type === \App\Enums\InvoiceLineType::Fine && $line->meta !== null)
                            {{-- Full fine breakdown so every figure can be verified (FR-FIN-12). --}}
                            <ul class="breakdown">
                                <li>Method: {{ \App\Enums\FineMethod::from($line->meta['method'])->label() }}</li>
                                <li>Due date: {{ $line->meta['due_date'] }}{{ ($line->meta['allowance_days'] ?? 0) > 0 ? ' (+'.$line->meta['allowance_days'].' allowance days)' : '' }} — computed as of {{ $line->meta['as_of'] }}</li>
                                <li>{{ $line->meta['late_days'] }} day(s) late{{ $line->meta['overdue_months'] !== null ? ' · '.$line->meta['overdue_months'].' overdue month(s)' : '' }}</li>
                                @if ($line->meta['daily_laari'] !== null)
                                    <li>Daily amount: {{ \App\Support\Money::fromLaari($line->meta['daily_laari'])->format() }}</li>
                                @endif
                                @foreach ($line->meta['tier_lines'] ?? [] as $tier)
                                    <li>{{ $tier['label'] }} = {{ \App\Support\Money::fromLaari($tier['amount_laari'])->format() }}</li>
                                @endforeach
                                @if ($line->meta['capped'])
                                    <li>Capped at {{ \App\Support\Money::fromLaari($line->meta['cap_laari'])->format() }}</li>
                                @endif
                            </ul>
                        @endif
                    </td>
                    <td class="num">{{ $line->amount()->format() }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td>Total due</td>
                <td class="num">{{ $invoice->total()->format() }}</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <p><strong>Payment account:</strong> {{ config('billing.payment_account') }}</p>
        <p>Please quote invoice number {{ $invoice->number }} when paying. Late payment attracts a fine per the lease agreement.</p>
    </div>
</body>
</html>
