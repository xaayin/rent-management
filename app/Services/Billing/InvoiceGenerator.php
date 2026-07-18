<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\CsrType;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Enums\RentBasis;
use App\Exceptions\InvalidInvoiceRangeException;
use App\Models\Invoice;
use App\Models\Lease;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
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
    public function __construct(
        private readonly InvoiceNumberGenerator $numbers,
        private readonly DueDateCalculator $dueDates,
    ) {}

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

        $covering = $this->overlapping($lease, $period, $period->endOfMonth());

        if ($covering->isNotEmpty()) {
            // Exactly this month → idempotent re-run; covered by an advance
            // invoice → already billed, nothing to do (FR-INV-06).
            $exact = $covering->first(fn (Invoice $invoice): bool => $invoice->period_year === $period->year
                && $invoice->period_month === $period->month
                && $invoice->period_months === 1);

            return $exact;
        }

        return $this->create($lease, $period, 1);
    }

    /**
     * Advance billing (FR-INV-05): one invoice covering $months consecutive
     * billing months from $from — a quarter, a year, or the whole remaining
     * lease term, paid up-front as a single document.
     */
    public function generateRange(Lease $lease, CarbonImmutable $from, int $months): Invoice
    {
        $from = $from->startOfMonth();

        $this->assertRangeBillable($lease, $from, $months);

        return $this->create($lease, $from, $months);
    }

    /**
     * Validate an advance range without creating anything — used by the UI
     * preview so conflicts surface before submitting.
     */
    public function assertRangeBillable(Lease $lease, CarbonImmutable $from, int $months): void
    {
        $from = $from->startOfMonth();

        if ($months < 1) {
            throw InvalidInvoiceRangeException::invalidMonths();
        }

        if ($lease->status !== LeaseStatus::Active) {
            throw InvalidInvoiceRangeException::leaseNotActive();
        }

        $effectiveStart = $lease->effectiveRentStart()->startOfMonth();

        if ($from->lessThan($effectiveStart)) {
            throw InvalidInvoiceRangeException::beforeRentStart($lease->effectiveRentStart()->toDateString());
        }

        $lastMonth = $from->addMonths($months - 1);
        $expiry = CarbonImmutable::parse($lease->expiry_date);

        if (! $lastMonth->lessThan($expiry)) {
            throw InvalidInvoiceRangeException::beyondExpiry($expiry->toDateString());
        }

        $conflicts = $this->overlapping($lease, $from, $lastMonth->endOfMonth());

        if ($conflicts->isNotEmpty()) {
            throw InvalidInvoiceRangeException::overlaps($conflicts->pluck('number')->all());
        }
    }

    /**
     * Invoices of the lease whose period range intersects [$start, $end].
     *
     * @return Collection<int, Invoice>
     */
    private function overlapping(Lease $lease, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return Invoice::query()
            ->where('lease_id', $lease->id)
            ->whereDate('period_start', '<=', $end->toDateString())
            ->whereDate('period_end', '>=', $start->toDateString())
            ->get();
    }

    private function create(Lease $lease, CarbonImmutable $from, int $months): Invoice
    {
        return DB::transaction(function () use ($lease, $from, $months): Invoice {
            [$lines, $rent, $charges] = $this->buildLines($lease, $from, $months);

            $invoice = Invoice::create([
                'number' => $this->numbers->next($from->year),
                'lease_id' => $lease->id,
                'period_year' => $from->year,
                'period_month' => $from->month,
                'period_months' => $months,
                'period_start' => $from->toDateString(),
                'period_end' => $from->addMonths($months - 1)->endOfMonth()->toDateString(),
                'due_date' => $this->dueDates->for($lease, $from)->toDateString(),
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
    private function buildLines(Lease $lease, CarbonImmutable $from, int $months = 1): array
    {
        $position = 0;
        $monthlyRent = $lease->monthlyRent()->laari;
        $rent = $monthlyRent * $months;

        $lines = [[
            'type' => InvoiceLineType::Rent->value,
            'description' => $this->rentDescription($lease, $from, $months),
            'amount_laari' => $rent,
            'meta' => array_merge($this->rentMeta($lease), [
                'months' => $months,
                'monthly_rent_laari' => $monthlyRent,
            ]),
            'position' => $position++,
        ]];

        $charges = 0;

        // One CSR charge for every occurrence of the CSR month inside the
        // covered range — a two-year advance with an annual CSR bills it twice.
        if ($lease->hasCsr()) {
            for ($offset = 0; $offset < $months; $offset++) {
                $month = $from->addMonths($offset);

                if ($month->month !== $lease->effectiveCsrMonth()) {
                    continue;
                }

                $amount = $lease->csrAnnualAmount()->laari;
                $charges += $amount;

                $lines[] = [
                    'type' => InvoiceLineType::Csr->value,
                    'description' => $this->csrDescription($lease).($months > 1 ? ' — '.$month->format('Y') : ''),
                    'amount_laari' => $amount,
                    'meta' => $this->csrMeta($lease),
                    'position' => $position++,
                ];
            }
        }

        return [$lines, $rent, $charges];
    }

    private function rentDescription(Lease $lease, CarbonImmutable $from, int $months): string
    {
        $basis = $lease->rent_basis === RentBasis::PerSquareFoot
            ? number_format((int) $lease->area_sqft).' ft² × '.Money::fromLaari((int) $lease->rate_laari)->format().'/ft²'
            : 'flat '.$lease->monthlyRent()->format().'/month';

        if ($months === 1) {
            return "Monthly rent — {$basis}";
        }

        return sprintf(
            'Rent %s to %s — %d months × %s (%s)',
            $from->format('M Y'),
            $from->addMonths($months - 1)->format('M Y'),
            $months,
            $lease->monthlyRent()->format(),
            $basis,
        );
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
