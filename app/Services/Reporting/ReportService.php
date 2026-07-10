<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\InvoiceStatus;
use App\Enums\LeaseStatus;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregations behind the dashboard and reports (PRD §4.9). All amounts stay
 * in integer laari; formatting happens at the view boundary. Queries are
 * aggregate SQL — no per-row loops over the whole ledger (NFR-PER-01).
 */
class ReportService
{
    /**
     * The five home-dashboard numbers (FR-RPT-01).
     *
     * @return array<string, int>
     */
    public function dashboard(CarbonImmutable $today): array
    {
        $billed = Invoice::query()
            ->where('period_year', $today->year)
            ->where('period_month', $today->month)
            ->selectRaw('COUNT(*) as invoices, COALESCE(SUM(rent_laari + charges_laari), 0) as laari')
            ->first();

        $collected = (int) Payment::query()
            ->whereBetween('payment_date', [
                $today->startOfMonth()->toDateString(),
                $today->endOfMonth()->toDateString(),
            ])
            ->sum('amount_laari');

        $arrears = $this->arrears($today);

        return [
            'active_leases' => Lease::query()->where('status', LeaseStatus::Active->value)->count(),
            'billed_laari' => (int) $billed->laari,
            'billed_invoices' => (int) $billed->invoices,
            'collected_laari' => $collected,
            'arrears_laari' => (int) $arrears->sum('outstanding_laari'),
            'overdue_invoices' => $arrears->count(),
            'fines_outstanding_laari' => (int) $arrears->sum('outstanding_fine_laari'),
        ];
    }

    /**
     * Overdue invoices by tenant with days overdue and the current fine
     * (FR-RPT-02), largest days first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function arrears(CarbonImmutable $today): Collection
    {
        return Invoice::query()
            ->with(['lease.tenant', 'lease.property'])
            ->whereDate('due_date', '<', $today->toDateString())
            ->whereIn('status', [
                InvoiceStatus::Issued->value,
                InvoiceStatus::PartlyPaid->value,
                InvoiceStatus::Overdue->value,
            ])
            ->withSum('payments as paid_laari', 'amount_laari')
            ->withSum('payments as paid_fine_laari', 'fine_allocated_laari')
            ->get()
            ->map(function (Invoice $invoice) use ($today): array {
                $outstanding = $invoice->total_laari - (int) $invoice->paid_laari;

                return [
                    'invoice' => $invoice,
                    'days_overdue' => (int) CarbonImmutable::parse($invoice->due_date->toDateString())->diffInDays($today),
                    'outstanding_laari' => $outstanding,
                    'outstanding_fine_laari' => max($invoice->fine_laari - (int) $invoice->paid_fine_laari, 0),
                ];
            })
            ->filter(fn (array $row): bool => $row['outstanding_laari'] > 0)
            ->sortByDesc('days_overdue')
            ->values();
    }

    /**
     * Active leases expiring — and grace periods ending — within the window
     * (FR-RPT-03, default 90 days).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function upcoming(CarbonImmutable $today, int $windowDays = 90): Collection
    {
        $until = $today->addDays($windowDays);

        $expiries = Lease::query()
            ->with(['property', 'tenant'])
            ->where('status', LeaseStatus::Active->value)
            ->whereBetween('expiry_date', [$today->toDateString(), $until->toDateString()])
            ->get()
            ->map(fn (Lease $lease): array => [
                'lease' => $lease,
                'kind' => 'expiry',
                'label' => 'Expires in '.(int) $today->diffInDays(CarbonImmutable::parse($lease->expiry_date)).' days · renewal due',
                'days' => (int) $today->diffInDays(CarbonImmutable::parse($lease->expiry_date)),
            ]);

        $graceEndings = Lease::query()
            ->with(['property', 'tenant'])
            ->where('status', LeaseStatus::Active->value)
            ->where('grace_months', '>', 0)
            ->get()
            ->filter(function (Lease $lease) use ($today, $until): bool {
                $graceEnd = $lease->effectiveRentStart();

                return $graceEnd->greaterThan($today) && $graceEnd->lessThanOrEqualTo($until);
            })
            ->map(fn (Lease $lease): array => [
                'lease' => $lease,
                'kind' => 'grace',
                'label' => 'Grace period ends in '.(int) $today->diffInDays($lease->effectiveRentStart()).' days',
                'days' => (int) $today->diffInDays($lease->effectiveRentStart()),
            ]);

        return $expiries->concat($graceEndings)->sortBy('days')->values();
    }

    /**
     * Income by month for a year — billed vs collected (FR-RPT-04).
     *
     * @return Collection<int, array{month: int, billed_laari: int, collected_laari: int}>
     */
    public function incomeByMonth(int $year): Collection
    {
        $billed = Invoice::query()
            ->where('period_year', $year)
            ->groupBy('period_month')
            ->selectRaw('period_month, COALESCE(SUM(total_laari), 0) as laari')
            ->pluck('laari', 'period_month');

        // Month extraction differs per driver (MySQL in production, SQLite in
        // tests) — pick the right expression.
        $monthExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%m', payment_date) AS INTEGER)"
            : 'MONTH(payment_date)';

        $collected = Payment::query()
            ->whereYear('payment_date', $year)
            ->groupByRaw($monthExpr)
            ->selectRaw("{$monthExpr} as month, COALESCE(SUM(amount_laari), 0) as laari")
            ->pluck('laari', 'month');

        return collect(range(1, 12))->map(fn (int $month): array => [
            'month' => $month,
            'billed_laari' => (int) ($billed[$month] ?? 0),
            'collected_laari' => (int) ($collected[$month] ?? 0),
        ]);
    }

    /**
     * Collected income grouped by property usage type (FR-RPT-04).
     *
     * @return Collection<int, object{group: string, laari: int}>
     */
    public function incomeByPropertyType(int $year): Collection
    {
        return DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->join('leases', 'leases.id', '=', 'invoices.lease_id')
            ->join('properties', 'properties.id', '=', 'leases.property_id')
            ->whereYear('payments.payment_date', $year)
            ->groupBy('properties.usage_type')
            ->selectRaw('properties.usage_type as `group`, COALESCE(SUM(payments.amount_laari), 0) as laari')
            ->orderByDesc('laari')
            ->get();
    }

    /**
     * Collected income grouped by tenant type (FR-RPT-04).
     *
     * @return Collection<int, object{group: string, laari: int}>
     */
    public function incomeByTenantType(int $year): Collection
    {
        return DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->join('leases', 'leases.id', '=', 'invoices.lease_id')
            ->join('tenants', 'tenants.id', '=', 'leases.tenant_id')
            ->whereYear('payments.payment_date', $year)
            ->groupBy('tenants.type')
            ->selectRaw('tenants.type as `group`, COALESCE(SUM(payments.amount_laari), 0) as laari')
            ->orderByDesc('laari')
            ->get();
    }
}
