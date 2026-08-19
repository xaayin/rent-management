<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An advance-billing range that violates the billing rules (§5.2, FR-INV-04/06).
 */
class InvalidInvoiceRangeException extends RuntimeException
{
    /** A reason stated in full by the caller — shown to the user verbatim. */
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function leaseNotActive(): self
    {
        return new self('Only active leases can be invoiced.');
    }

    public static function beforeRentStart(string $effectiveStart): self
    {
        return new self("The range starts before rent begins ({$effectiveStart}, after any grace period).");
    }

    public static function beyondExpiry(string $expiry): self
    {
        return new self("The range extends past the lease expiry ({$expiry}).");
    }

    /**
     * @param  list<string>  $numbers
     */
    public static function overlaps(array $numbers): self
    {
        return new self('The range overlaps existing invoice(s): '.implode(', ', $numbers).'.');
    }

    public static function invalidMonths(): self
    {
        return new self('The number of months must be at least 1.');
    }
}
