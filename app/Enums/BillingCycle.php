<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The billing cycle of a lease (FR-CHG-05). Monthly is the only supported cycle
 * for now; the enum leaves room for others without touching call sites.
 */
enum BillingCycle: string
{
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
        };
    }
}
