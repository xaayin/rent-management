<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A payment or reversal that violates the payment rules (§4.6, §5.4).
 */
class InvalidPaymentException extends RuntimeException
{
    public static function notPositive(): self
    {
        return new self('The payment amount must be greater than zero.');
    }

    public static function exceedsOutstanding(): self
    {
        return new self('The payment exceeds the outstanding balance on this invoice.');
    }

    public static function alreadyReversed(): self
    {
        return new self('This payment has already been reversed.');
    }

    public static function cannotReverseReversal(): self
    {
        return new self('A reversal entry cannot itself be reversed.');
    }
}
