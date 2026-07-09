<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lease lifecycle status (FR-LSE-02). Only Active leases are invoiced
 * (FR-LSE-03); Expired is auto-derived once the expiry date passes.
 */
enum LeaseStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Terminated = 'terminated';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Terminated => 'Terminated',
            self::Expired => 'Expired',
        };
    }
}
