<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\CsrType;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\RentBasis;
use App\Models\Invoice;
use App\Models\Lease;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds the invoice for one lease and one monthly billing period (FR-INV-01).
 *
 * Generation is idempotent: a unique (lease, year, month) key means re-running
 * for the same period returns the existing invoice rather than duplicating it
 * (FR-INV-06). Billability honours grace, rent-start and expiry (FR-INV-04).
 */
class InvoiceGenerator
{
    public function __construct(private readonly InvoiceNumberGenerator $numbers) {}

    /**
     * Generate (or return the existing) invoice for the given billing month.
     * Returns null when the period is not billable for this lease.
     */
    public function generate(Lease $lease, CarbonImmutable $period): ?Invoice
    {
        $period = $period->startOfMonth();

        if (! $this->isBillable($lease, $period)) {
            return null;
        }

        $existing = Invoice::query()
            ->where('lease_id', $lease->id)
            ->where('period_year', $period->year)
            ->where('period_month', $period->month)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($lease, $period): Invoice {
            [$lines, $rent, $charges] = $this->buildLines($lease, $period);

            $dueDay = min((int) $lease->due_day, $period->daysInMonth);

            $invoice = Invoice::create([
                'number' => $this->numbers->next($period->year),
                'lease_id' => $lease->id,
                'period_year' => $period->year,
                'period_month' => $period->month,
                'period_start' => $period->toDateString(),
                'period_end' => $period->endOfMonth()->toDateString(),
                'due_date' => $period->day($dueDay)->toDateString(),
                'status' => InvoiceStatus::Issued->value,
                'rent_laari' => $rent,
                'charges_laari' => $charges,
                'fine_laari' => 0,
                'total_laari' => $rent + $charges,
            ]);

            foreach ($lines as $line) {
                $invoice->lineItems()->create($line);
            }

            return $invoice->load('lineItems');
        });
    }

    /**
     * Whether the given month should be invoiced for this lease (§5.2):
     * the lease is active, the month is at or after the post-grace rent start,
     * and it is before the expiry date.
     */
    public function isBillable(Lease $lease, CarbonImmutable $period): bool
    {
        if (! $lease->isActive()) {
            return false;
        }

        $periodStart = $period->startOfMonth();

        if ($periodStart->lessThan($lease->effectiveRentStart()->startOfMonth())) {
            return false;
        }

        return $periodStart->lessThan(CarbonImmutable::parse($lease->expiry_date));
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     */
    private function buildLines(Lease $lease, CarbonImmutable $period): array
    {
        $position = 0;
        $rent = $lease->monthlyRent()->laari;

        $lines = [[
            'type' => InvoiceLineType::Rent->value,
            'description' => $this->rentDescription($lease),
            'amount_laari' => $rent,
            'meta' => $this->rentMeta($lease),
            'position' => $position++,
        ]];

        $charges = 0;

        if ($lease->hasCsr() && $period->month === $lease->effectiveCsrMonth()) {
            $charges = $lease->csrAnnualAmount()->laari;

            $lines[] = [
                'type' => InvoiceLineType::Csr->value,
                'description' => $this->csrDescription($lease),
                'amount_laari' => $charges,
                'meta' => $this->csrMeta($lease),
                'position' => $position++,
            ];
        }

        return [$lines, $rent, $charges];
    }

    private function rentDescription(Lease $lease): string
    {
        if ($lease->rent_basis === RentBasis::PerSquareFoot) {
            return 'Monthly rent — '.number_format((int) $lease->area_sqft).' ft² × '
                .Money::fromLaari((int) $lease->rate_laari)->format().'/ft²';
        }

        return 'Monthly rent (flat)';
    }

    /**
     * @return array<string, mixed>
     */
    private function rentMeta(Lease $lease): array
    {
        if ($lease->rent_basis === RentBasis::PerSquareFoot) {
            return ['basis' => 'per_sqft', 'rate_laari' => (int) $lease->rate_laari, 'area_sqft' => (int) $lease->area_sqft];
        }

        return ['basis' => 'flat', 'flat_amount_laari' => (int) $lease->flat_amount_laari];
    }

    private function csrDescription(Lease $lease): string
    {
        if ($lease->csr_type === CsrType::PercentOfRevenue) {
            $percent = rtrim(rtrim(number_format((int) $lease->csr_percent_bps / 100, 2), '0'), '.');

            return "CSR charge (annual — {$percent}% of declared revenue)";
        }

        return 'CSR charge (annual, fixed)';
    }

    /**
     * @return array<string, mixed>
     */
    private function csrMeta(Lease $lease): array
    {
        return [
            'csr_type' => $lease->csr_type->value,
            'amount_laari' => $lease->csrAnnualAmount()->laari,
            'percent_bps' => $lease->csr_percent_bps,
            'declared_revenue_laari' => $lease->csr_declared_revenue_laari,
        ];
    }
}
