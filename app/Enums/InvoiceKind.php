<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an invoice demands. A rent demand and an annual CSR charge are
 * different documents with different rules, even though they share numbering,
 * payments, statements and PDFs.
 *
 * Whether late payment accrues a fine is a property of the KIND — a stated
 * rule, never an accident of a zero rent base.
 */
enum InvoiceKind: string
{
    case Rent = 'rent';
    case Csr = 'csr';

    public function label(): string
    {
        return match ($this) {
            self::Rent => 'Rent',
            self::Csr => 'CSR',
        };
    }

    /** Does late payment of this document accrue a fine? */
    public function finable(): bool
    {
        return match ($this) {
            self::Rent => true,
            self::Csr => false,
        };
    }
}
