<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\DueDateAnchor;
use App\Models\Lease;
use Carbon\CarbonImmutable;

/**
 * The ONE place an invoice due date is computed (council rule, FR-INV/§4.5).
 *
 * The generator and every on-screen preview go through here, so the rule can
 * never drift between what a preview promises and what an invoice records.
 * The rule itself is configuration (config/billing.php `due_date_anchor`) —
 * the council has already changed its mind once, so it will again.
 *
 * Changing the config affects invoices generated FROM THEN ON; existing
 * invoices keep the due date they were issued with (fines are computed from
 * the stored date — money records never reinterpret themselves).
 */
class DueDateCalculator
{
    /**
     * The due date for a lease's invoice whose billed period starts at
     * $periodStart (always the 1st of the billed month).
     */
    public function for(Lease $lease, CarbonImmutable $periodStart): CarbonImmutable
    {
        $dueMonth = $periodStart->startOfMonth()->addMonths($this->monthOffset($lease));

        // The lease's own due day, clamped to the month it lands in (a due
        // day of 30 in February becomes the 28th, not a spill into March).
        return $dueMonth->day(min((int) $lease->due_day, $dueMonth->daysInMonth));
    }

    private function monthOffset(Lease $lease): int
    {
        return match ($this->anchor()) {
            DueDateAnchor::SameMonth => 0,
            DueDateAnchor::NextMonth => 1,
            // The recurring period is anchored on the day rent actually starts
            // (grace included): on the 1st → pay within the month; mid-month →
            // the period runs into the next month, so it falls due there.
            DueDateAnchor::StartDayBased => $lease->effectiveRentStart()->day === 1 ? 0 : 1,
        };
    }

    public function anchor(): DueDateAnchor
    {
        return DueDateAnchor::tryFrom((string) config('billing.due_date_anchor'))
            ?? DueDateAnchor::StartDayBased;
    }
}
