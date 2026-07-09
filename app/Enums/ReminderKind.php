<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * When a payment reminder fires relative to the invoice due date (FR-NOT-02),
 * plus the staff-triggered manual send (FR-NOT-08).
 */
enum ReminderKind: string
{
    case PreDue = 'pre_due';
    case OnDue = 'on_due';
    case Overdue = 'overdue';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::PreDue => 'Before the due date',
            self::OnDue => 'On the due date',
            self::Overdue => 'After the due date (overdue)',
            self::Manual => 'Manual',
        };
    }
}
