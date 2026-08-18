<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\InvoiceStatus;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The per-tenant account statement (FR-PAY-05): every invoice, payment,
 * reversal and the running balance, consolidated across the tenant's leases
 * (FR-TEN-05).
 *
 * Extracted from the staff statement screen so the tenant portal renders the
 * SAME ledger — the tenant must never see different figures from the counter.
 */
class TenantLedger
{
    /**
     * Chronological ledger rows with a running balance in laari.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function entries(Tenant $tenant): Collection
    {
        $entries = collect();

        $leases = $tenant->leases()
            ->with([
                'property',
                'invoices' => fn ($query) => $query->where('status', '!=', InvoiceStatus::Cancelled->value),
                'invoices.payments.receipt',
            ])
            ->get();

        foreach ($leases as $lease) {
            foreach ($lease->invoices as $invoice) {
                $detail = 'Rent '.$invoice->rent()->format();

                if ($invoice->charges_laari > 0) {
                    $detail .= ' · CSR '.$invoice->charges()->format();
                }

                if ($invoice->fine_laari > 0) {
                    $detail .= ' · Fine '.$invoice->fine()->format();
                }

                $entries->push([
                    'sort' => $invoice->period_start->toDateString().'-1-'.$invoice->id,
                    'date' => $invoice->period_start->toDateString(),
                    'label' => "Invoice {$invoice->number} — {$lease->property->name}, {$invoice->periodLabel()}",
                    'detail' => $detail,
                    'debit' => $invoice->total_laari,
                    'credit' => 0,
                ]);

                foreach ($invoice->payments as $payment) {
                    if ($payment->isReversal()) {
                        $entries->push([
                            'sort' => $payment->payment_date->toDateString().'-2-'.$payment->id,
                            'date' => $payment->payment_date->toDateString(),
                            'label' => "Reversal of receipt {$payment->reference} — invoice {$invoice->number}",
                            'detail' => (string) $payment->reversal_reason,
                            'debit' => -$payment->amount_laari,
                            'credit' => 0,
                        ]);
                    } else {
                        $entries->push([
                            'sort' => $payment->payment_date->toDateString().'-2-'.$payment->id,
                            'date' => $payment->payment_date->toDateString(),
                            'label' => "Payment — receipt {$payment->receipt_number} ({$payment->method->label()})",
                            'detail' => 'Rent '.$payment->principalAllocated()->format().' · Fine '.$payment->fineAllocated()->format(),
                            'credit' => $payment->amount_laari,
                            'debit' => 0,
                        ]);
                    }
                }
            }
        }

        $running = 0;

        return $entries
            ->sortBy('sort')
            ->values()
            ->map(function (array $entry) use (&$running): array {
                $running += $entry['debit'] - $entry['credit'];
                $entry['balance'] = $running;

                return $entry;
            });
    }

    /**
     * The closing balance of the ledger — what the tenant owes overall.
     */
    public function balance(Collection $entries): Money
    {
        return Money::fromLaari((int) $entries->sum('debit') - (int) $entries->sum('credit'));
    }
}
