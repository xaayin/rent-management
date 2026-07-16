<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * When a payment reminder fires relative to the invoice due date (FR-NOT-02),
 * plus the staff-triggered manual send (FR-NOT-08), and the tenant-facing
 * notices that are not date-offset-driven: the payment confirmation sent the
 * moment a receipt is issued, and the monthly balance statement for tenants
 * in arrears.
 */
enum ReminderKind: string
{
    case PreDue = 'pre_due';
    case OnDue = 'on_due';
    case Overdue = 'overdue';
    case Manual = 'manual';
    case PaymentConfirmation = 'payment_confirmation';
    case BalanceStatement = 'balance_statement';
    case PortalOtp = 'portal_otp';

    public function label(): string
    {
        return match ($this) {
            self::PreDue => 'Before the due date',
            self::OnDue => 'On the due date',
            self::Overdue => 'After the due date (overdue)',
            self::Manual => 'Manual',
            self::PaymentConfirmation => 'Payment received (confirmation)',
            self::BalanceStatement => 'Monthly balance statement',
            self::PortalOtp => 'Portal sign-in code',
        };
    }

    /**
     * Whether this kind fires relative to an invoice due date — the daily
     * reminder run only processes these; the others have their own triggers
     * (a receipt being issued; the monthly statement command).
     */
    public function isDueDateDriven(): bool
    {
        return match ($this) {
            self::PreDue, self::OnDue, self::Overdue => true,
            self::Manual, self::PaymentConfirmation, self::BalanceStatement, self::PortalOtp => false,
        };
    }
}
