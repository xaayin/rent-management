<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Property usage types (FR-PRP-01). FR-PRP-04 (an admin-configurable list) is a
 * "Should" and is deferred; this fixed set covers the Must requirement.
 */
enum UsageType: string
{
    case Commercial = 'commercial';
    case Agricultural = 'agricultural';
    case CafeRestaurant = 'cafe_restaurant';
    case TeaShop = 'tea_shop';
    case BoatShed = 'boat_shed';
    case TelecomAntenna = 'telecom_antenna';
    case VacantLand = 'vacant_land';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Commercial => 'Commercial',
            self::Agricultural => 'Agricultural',
            self::CafeRestaurant => 'Café / restaurant',
            self::TeaShop => 'Tea-shop',
            self::BoatShed => 'Boat shed',
            self::TelecomAntenna => 'Telecom antenna',
            self::VacantLand => 'Vacant land',
            self::Other => 'Other',
        };
    }
}
