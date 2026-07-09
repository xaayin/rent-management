<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of line item that make up an invoice (FR-INV-02).
 */
enum InvoiceLineType: string
{
    case Rent = 'rent';
    case Csr = 'csr';
    case Fine = 'fine';

    public function label(): string
    {
        return match ($this) {
            self::Rent => 'Rent',
            self::Csr => 'CSR charge',
            self::Fine => 'Late fine',
        };
    }
}
