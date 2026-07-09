<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How rent is charged for a lease (FR-CHG-01): either a rate per ft² × area, or
 * a flat periodic amount. Amounts are stored in integer laari.
 */
enum RentBasis: string
{
    case PerSquareFoot = 'per_sqft';
    case Flat = 'flat';

    public function label(): string
    {
        return match ($this) {
            self::PerSquareFoot => 'Rate per ft²',
            self::Flat => 'Flat monthly amount',
        };
    }
}
