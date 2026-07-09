<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A ledger entry against an invoice: a real payment, or the reversing entry
 * that corrects one (FR-PAY-06 — corrections never delete).
 */
enum PaymentType: string
{
    case Payment = 'payment';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Payment',
            self::Reversal => 'Reversal',
        };
    }
}
