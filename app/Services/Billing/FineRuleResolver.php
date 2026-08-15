<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\FineRuleAnchor;
use App\Models\FineRule;
use App\Models\Invoice;
use Carbon\CarbonImmutable;

/**
 * The ONE place that answers "which fine period governs this invoice".
 *
 * The applier, the on-screen history and the scheduler's edit/delete guard all
 * come through here, so what fines an invoice and what locks a period can never
 * drift apart.
 *
 * The anchor is config (`billing.fine_rule_anchor`): by default the month the
 * invoice BILLS, not the day the row was created. Back-entering last year's
 * paperwork must not fine it under this year's rule.
 */
class FineRuleResolver
{
    public function anchor(): FineRuleAnchor
    {
        return FineRuleAnchor::tryFrom((string) config('billing.fine_rule_anchor'))
            ?? FineRuleAnchor::PeriodStart;
    }

    /**
     * The date on this invoice that decides which period governs it. An advance
     * invoice is anchored on the first month it covers, so one document is never
     * fined under two rules.
     */
    public function anchorDate(Invoice $invoice): CarbonImmutable
    {
        $date = match ($this->anchor()) {
            FineRuleAnchor::PeriodStart => $invoice->period_start,
            FineRuleAnchor::DueDate => $invoice->due_date,
            FineRuleAnchor::IssueDate => $invoice->created_at,
        };

        return CarbonImmutable::parse($date->toDateString());
    }

    /** The governing period, or null when the anchor falls in a no-fine gap. */
    public function for(Invoice $invoice): ?FineRule
    {
        return $invoice->lease?->fineRuleOn($this->anchorDate($invoice));
    }
}
