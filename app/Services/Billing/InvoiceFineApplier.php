<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\FineBase;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Marks unpaid invoices past their due date as Overdue and (re)computes their
 * accruing fine (FR-FIN-04/05/11), fully itemised on a dedicated line item
 * whose meta holds the breakdown (FR-FIN-12).
 *
 * The rule applied is the period governing the month the invoice bills
 * (FineRuleResolver, effective-dated — §5.3.22); a lease with no rule accrues
 * no fine. The fine
 * accrues only while the principal (rent + charges) is outstanding; once the
 * principal is settled the fine is frozen at the value computed on that
 * payment date (§5.3.15, §5.4.25).
 */
class InvoiceFineApplier
{
    public function __construct(
        private readonly FineCalculator $calculator,
        private readonly FineRuleResolver $rules,
    ) {}

    /**
     * The daily refresh: status transition plus fine recomputation.
     */
    public function apply(Invoice $invoice, CarbonImmutable $asOf): void
    {
        if (! in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Overdue, InvoiceStatus::PartlyPaid], true)) {
            return; // never touch settled invoices
        }

        $asOf = $asOf->startOfDay();
        $dueDate = CarbonImmutable::parse($invoice->due_date->toDateString());

        if (! $asOf->greaterThan($dueDate)) {
            return; // not yet overdue
        }

        DB::transaction(function () use ($invoice, $asOf): void {
            if ($invoice->status === InvoiceStatus::Issued) {
                $invoice->update(['status' => InvoiceStatus::Overdue->value]);
            }

            if ($invoice->outstandingPrincipalLaari() > 0) {
                $this->refreshFine($invoice, $asOf);
            }
        });
    }

    /**
     * Compute the fine as of a date without persisting anything — used for the
     * record-payment preview (design PRD §5.8). Null when no rule applies.
     */
    public function previewFine(Invoice $invoice, CarbonImmutable $asOf): ?FineBreakdown
    {
        // Fines are a property of the KIND: a CSR invoice still goes Overdue
        // (it is late, and reminders chase it) but never grows a fine — a
        // stated rule, not an accident of its zero rent base.
        if (! $invoice->kind->finable()) {
            return null;
        }

        $rule = $this->rules->for($invoice);

        if ($rule === null) {
            return null;
        }

        $baseLaari = $rule->base === FineBase::RentPlusCharges
            ? $invoice->rent_laari + $invoice->charges_laari
            : $invoice->rent_laari;

        return $this->calculator->calculate(
            $rule,
            Money::fromLaari($baseLaari),
            CarbonImmutable::parse($invoice->due_date->toDateString()),
            $asOf,
        );
    }

    /**
     * Recompute and persist the fine (and totals) as of a date — the payment
     * path uses this with the actual payment date (FR-PAY-02).
     */
    public function refreshFine(Invoice $invoice, CarbonImmutable $asOf): void
    {
        $breakdown = $this->previewFine($invoice, $asOf->startOfDay());

        if ($breakdown === null) {
            return;
        }

        DB::transaction(function () use ($invoice, $breakdown): void {
            $invoice->update([
                'fine_laari' => $breakdown->totalLaari,
                'total_laari' => $invoice->rent_laari + $invoice->charges_laari + $breakdown->totalLaari,
                // Name the period that produced this figure, so the fine on an
                // invoice can always be traced to the rule that made it.
                'fine_rule_id' => $breakdown->ruleId,
            ]);

            // The accruing fine line is recomputed daily by definition
            // (FR-FIN-05); replacing it is the specified behaviour, and the
            // invoice itself remains append-only.
            $invoice->lineItems()->where('type', InvoiceLineType::Fine->value)->delete();

            if ($breakdown->totalLaari > 0) {
                $invoice->lineItems()->create([
                    'type' => InvoiceLineType::Fine->value,
                    'description' => $breakdown->summary(),
                    'amount_laari' => $breakdown->totalLaari,
                    'meta' => $breakdown->toArray(),
                    'position' => 100,
                ]);
            }
        });
    }
}
