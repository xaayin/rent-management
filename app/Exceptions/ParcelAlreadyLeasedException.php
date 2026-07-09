<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a lease would be made Active on a parcel that already has another
 * active lease (FR-PRP-02: a property may not be leased to two active tenants
 * at once).
 */
class ParcelAlreadyLeasedException extends RuntimeException
{
    public static function forProperty(int $propertyId): self
    {
        return new self("Property [{$propertyId}] already has an active lease.");
    }
}
