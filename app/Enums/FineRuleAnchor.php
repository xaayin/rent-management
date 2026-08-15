<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which date on an invoice decides WHICH fine period governs it
 * (config/billing.php `fine_rule_anchor`).
 *
 * The council rule is PeriodStart: a charge belongs to the month it bills, so
 * an invoice for December 2025 is fined under the rule the council had in
 * December 2025 — even if the row was only entered months later, as happens
 * whenever historic paperwork is caught up.
 */
enum FineRuleAnchor: string
{
    /** The first month the invoice bills. */
    case PeriodStart = 'period_start';

    /** The day payment fell due. */
    case DueDate = 'due_date';

    /** The day the row was created — only meaningful if nothing is back-entered. */
    case IssueDate = 'issue_date';

    public function label(): string
    {
        return match ($this) {
            self::PeriodStart => 'The month the invoice bills',
            self::DueDate => 'The invoice due date',
            self::IssueDate => 'The date the invoice was raised',
        };
    }

    /** The invoices column this anchor reads, for set-based queries. */
    public function column(): string
    {
        return match ($this) {
            self::PeriodStart => 'period_start',
            self::DueDate => 'due_date',
            self::IssueDate => 'created_at',
        };
    }
}
