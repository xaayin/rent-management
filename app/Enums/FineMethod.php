<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three late-fine calculation methods, selectable per lease (FR-FIN-01).
 */
enum FineMethod: string
{
    case FlatPerDay = 'flat_per_day';
    case PercentPerDay = 'percent_per_day';
    case TieredMonthly = 'tiered_monthly';

    public function label(): string
    {
        return match ($this) {
            self::FlatPerDay => 'Flat amount per day',
            self::PercentPerDay => 'Percentage per day',
            self::TieredMonthly => 'Tiered fixed-monthly',
        };
    }
}
