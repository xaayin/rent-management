<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An invoice that must not be voided. Voiding is only ever for a document
 * raised in error and never settled — anything money has touched is corrected
 * by reversing the payment, not by making the demand disappear.
 */
class InvalidInvoiceCancellationException extends RuntimeException
{
    public static function alreadyCancelled(string $number): self
    {
        return new self("Invoice {$number} has already been cancelled.");
    }

    public static function hasPayments(string $number): self
    {
        return new self(
            "Invoice {$number} has a payment recorded against it and cannot be cancelled. "
            .'Reverse the payment first, then cancel.'
        );
    }

    public static function reasonRequired(): self
    {
        return new self('A reason is required to cancel an invoice.');
    }
}
