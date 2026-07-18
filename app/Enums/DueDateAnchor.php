<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which month an invoice's due date lands in, relative to the period it bills
 * (config/billing.php `due_date_anchor`). The day within that month is always
 * the lease's own `due_day`.
 *
 * The council's rule in force is StartDayBased: a lease whose rent period is
 * anchored ON the 1st falls due in the same month; one anchored mid-month
 * falls due the following month — exactly how the paper ledgers worked (the
 * 25 Apr – 25 May period fell due on 10 May).
 */
enum DueDateAnchor: string
{
    /** Due in the billed month itself, whatever day the lease started. */
    case SameMonth = 'same_month';

    /** Due in the month after the billed month, always. */
    case NextMonth = 'next_month';

    /** Same month when rent starts on the 1st; next month otherwise. */
    case StartDayBased = 'start_day_based';

    public function label(): string
    {
        return match ($this) {
            self::SameMonth => 'Same month as the billed period',
            self::NextMonth => 'Month after the billed period',
            self::StartDayBased => 'Same month if rent starts on the 1st, next month otherwise',
        };
    }
}
