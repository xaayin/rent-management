<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $receipt->number }}</title>
    <style>
        /* Kanduhulhudhoo Council letterhead palette (design/council-ds). */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Noto Sans Thaana', ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #12203A; font-size: 13px; padding: 40px; }
        h1 { font-size: 22px; margin-bottom: 2px; letter-spacing: -0.02em; }
        .muted { color: #6B7888; }
        .letterhead { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; border-bottom: 2px solid #1D2B45; padding-bottom: 16px; }
        .letterhead img { height: 52px; }
        .contact { text-align: right; font-size: 11px; color: #6B7888; line-height: 1.55; }
        .contact strong { color: #12203A; }
        .header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 28px; }
        .header .right { text-align: right; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: #9AA6B4; border-bottom: 1px solid #DBE1E8; padding: 6px 8px; font-weight: 700; }
        td { padding: 8px; border-bottom: 1px solid #E6EAEF; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .total-row td { border-top: 2px solid #1D2B45; border-bottom: none; font-weight: 700; font-size: 15px; }
        .footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid #DBE1E8; font-size: 12px; color: #6B7888; }
    </style>
    @include('pdf.partials.fonts')
</head>
<body>
    <div class="letterhead">
        <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/council/lockup-horizontal-color.png'))) }}" alt="Secretariat of the Kanduhulhudhoo Council">
        <div class="contact">
            <p><strong>Secretariat of the Kanduhulhudhoo Council</strong></p>
            <p>Ga. Kanduhulhudhoo, Republic of Maldives</p>
            <p>Tel 6820020 · info@kanduhulhudhoo.gov.mv</p>
        </div>
    </div>

    @php
        $first = $receipt->payments->first();
        $tenant = $receipt->tenant;
        $principalTotal = \App\Support\Money::fromLaari((int) $receipt->payments->sum('principal_allocated_laari'));
        $fineTotal = \App\Support\Money::fromLaari((int) $receipt->payments->sum('fine_allocated_laari'));
    @endphp

    <div class="header">
        <div>
            <p class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.08em">Official payment receipt</p>
        </div>
        <div class="right">
            <h1>Receipt {{ $receipt->number }}</h1>
            <p class="muted">Payment date: <strong>{{ $first->payment_date->format('j F Y') }}</strong></p>
            <p class="muted">Method: {{ $first->method->label() }}@if ($first->reference) · Ref: {{ $first->reference }}@endif</p>
        </div>
    </div>

    <p style="margin-bottom:16px">
        Received from <strong>{{ $tenant->name }}</strong>
        @if ($tenant->registryNumber())
            ({{ $tenant->registryNumber() }})
        @endif
        —
        {{-- One handover can settle several invoices, so the receipt itemises
             where the money went rather than implying a single charge. --}}
        @if ($receipt->isSplit())
            {{ $receipt->total()->format() }} applied across {{ $receipt->payments->count() }} invoices.
        @else
            against invoice <strong>{{ $first->invoice->number }}</strong>,
            {{ $first->invoice->lease->property->name }},
            {{ $first->invoice->period_start->format('F Y') }}.
        @endif
    </p>

    <table>
        <thead>
            <tr>
                <th>{{ $receipt->isSplit() ? 'Applied to' : 'Allocation' }}</th>
                <th class="num">Rent &amp; charges</th>
                <th class="num">Late fine</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($receipt->payments as $line)
                <tr>
                    <td>
                        <strong>{{ $line->invoice->number }}</strong>
                        <span class="muted"> · {{ $line->invoice->periodLabel() }}</span>
                        <span class="breakdown" style="display:block">{{ $line->invoice->lease->property->name }}</span>
                    </td>
                    <td class="num">{{ $line->principalAllocated()->format() }}</td>
                    <td class="num">{{ $line->fineAllocated()->format() }}</td>
                    <td class="num">{{ $line->amount()->format() }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td>Total received</td>
                <td class="num">{{ $principalTotal->format() }}</td>
                <td class="num">{{ $fineTotal->format() }}</td>
                <td class="num">{{ $receipt->total()->format() }}</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        {{-- Only what is still owed: a settled receipt listing "MVR 0.00" eight
             times buries the one line that matters when something is short. --}}
        @php $stillOwing = $receipt->payments->filter(fn ($line) => $line->invoice->outstandingTotalLaari() > 0); @endphp
        @if ($stillOwing->isEmpty())
            <p><strong>Settled in full</strong> — no balance remaining on {{ $receipt->isSplit() ? 'any of these invoices' : 'this invoice' }}.</p>
        @else
            @foreach ($stillOwing as $line)
                <p><strong>Balance remaining on invoice {{ $line->invoice->number }}:</strong> {{ $line->invoice->outstandingTotal()->format() }}</p>
            @endforeach
        @endif
        <p>This receipt was generated by {{ config('app.name') }}. Receipt numbers are sequential and never reissued.</p>
    </div>
</body>
</html>
