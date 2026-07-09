<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a payment was made (FR-PAY-01).
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank transfer',
            self::Cheque => 'Cheque',
            self::Other => 'Other',
        };
    }
}
