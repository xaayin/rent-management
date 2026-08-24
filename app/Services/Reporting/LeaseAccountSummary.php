<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Services\Billing\InvoiceFineApplier;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a lease owes RIGHT NOW — the figure the lease page leads with and the
 * list peek echoes, so both always agree.
 *
 * The fine is recomputed live through InvoiceFineApplier rather than read from
 * `invoices.fine_laari`, because that stored figure is only as fresh as the
 * last nightly refresh. Staff quote this number on the phone; it has to be the
 * number a payment taken today would actually settle.
 */
class LeaseAccountSummary
{
    public function __construct(private readonly InvoiceFineApplier $fines) {}

    /**
     * @return array{
     *     principal: Money, fine: Money, total: Money,
     *     unpaid_count: int, days_overdue: int, settled: bool,
     *     as_of: CarbonImmutable, invoices: Collection<int, array<string, mixed>>
     * }
     */
    public function for(Lease $lease, CarbonImmutable $asOf): array
    {
        $asOf = $asOf->startOfDay();

        $rows = $this->unpaidInvoices($lease)->map(function (Invoice $invoice) use ($asOf): array {
            $principal = max($invoice->outstandingPrincipalLaari(), 0);
            $due = CarbonImmutable::parse($invoice->due_date->toDateString());

            // Once the principal is settled the fine is frozen (§5.4.25), so
            // only a still-outstanding invoice gets a live recomputation.
            $fine = $principal > 0
                ? ($this->fines->previewFine($invoice, $asOf)?->totalLaari ?? 0)
                : max($invoice->outstandingFineLaari(), 0);

            return [
                'invoice' => $invoice,
                'principal' => Money::fromLaari($principal),
                'fine' => Money::fromLaari(max($fine - $invoice->paidFineLaari(), 0)),
                'total' => Money::fromLaari($principal + max($fine - $invoice->paidFineLaari(), 0)),
                'days_overdue' => $asOf->greaterThan($due) ? (int) $due->diffInDays($asOf) : 0,
                'due_date' => $due,
            ];
        });

        $principal = (int) $rows->sum(fn (array $row): int => $row['principal']->laari);
        $fine = (int) $rows->sum(fn (array $row): int => $row['fine']->laari);

        return [
            'principal' => Money::fromLaari($principal),
            'fine' => Money::fromLaari($fine),
            'total' => Money::fromLaari($principal + $fine),
            'unpaid_count' => $rows->count(),
            'days_overdue' => (int) $rows->max('days_overdue') ?: 0,
            'settled' => $principal + $fine <= 0,
            'as_of' => $asOf,
            'invoices' => $rows->sortBy(fn (array $row): string => $row['due_date']->toDateString())->values(),
        ];
    }

    /**
     * The same live figure summed across every lease a tenant holds. The lease
     * page shows both numbers together, so they must come from one engine —
     * a tenant balance computed from stale stored fines would sit on screen
     * BELOW the single lease it contains.
     */
    public function forTenant(Tenant $tenant, CarbonImmutable $asOf): Money
    {
        $laari = $tenant->leases()->get()
            ->sum(fn (Lease $lease): int => $this->for($lease, $asOf)['total']->laari);

        return Money::fromLaari((int) $laari);
    }

    /** @return Collection<int, Invoice> */
    private function unpaidInvoices(Lease $lease): Collection
    {
        return $lease->invoices()
            ->with('lease')
            ->whereIn('status', [
                InvoiceStatus::Issued->value,
                InvoiceStatus::PartlyPaid->value,
                InvoiceStatus::Overdue->value,
            ])
            ->orderBy('due_date')->orderBy('id')
            ->get();
    }
}
