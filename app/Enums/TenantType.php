<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A tenant is either an Individual (national ID) or an Organisation (company
 * registration number + contact person) — FR-TEN-01.
 */
enum TenantType: string
{
    case Individual = 'individual';
    case Organisation = 'organisation';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Organisation => 'Organisation',
        };
    }
}
