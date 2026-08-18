<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Invoice payment status (FR-PAY-04). Generation sets Issued; the Paid /
 * Partly paid / Overdue transitions are derived once payments and fines land
 * (Slices 4–5).
 */
enum InvoiceStatus: string
{
    case Issued = 'issued';
    case PartlyPaid = 'partly_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';

    /** Voided — raised in error, never paid, excluded from every balance. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Issued',
            self::PartlyPaid => 'Partly paid',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::Cancelled => 'Cancelled',
        };
    }
}
