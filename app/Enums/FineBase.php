<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a percentage/flat fine accrues against (FR-FIN-03): the rent alone, or
 * rent plus the cycle's additional charges.
 */
enum FineBase: string
{
    case Rent = 'rent';
    case RentPlusCharges = 'rent_plus_charges';

    public function label(): string
    {
        return match ($this) {
            self::Rent => 'rent only',
            self::RentPlusCharges => 'rent + charges',
        };
    }
}
