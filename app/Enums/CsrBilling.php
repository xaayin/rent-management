<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a lease's annual CSR charge is invoiced: as a line on the csr-month rent
 * invoice (the original behaviour), or as its own annual document. An
 * agreement term, so it lives on the lease.
 */
enum CsrBilling: string
{
    case WithRent = 'with_rent';
    case Separate = 'separate';

    public function label(): string
    {
        return match ($this) {
            self::WithRent => 'On the rent invoice',
            self::Separate => 'Separate annual invoice',
        };
    }
}
