<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The optional recurring CSR charge on a lease (FR-CHG-04): none, a fixed
 * annual amount, or a percentage of the tenant's declared revenue.
 */
enum CsrType: string
{
    case None = 'none';
    case FixedAnnual = 'fixed_annual';
    case PercentOfRevenue = 'percent_revenue';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::FixedAnnual => 'Fixed annual amount',
            self::PercentOfRevenue => 'Percentage of revenue',
        };
    }
}
