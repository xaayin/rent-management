<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A bank-transfer claim that violates the workflow rules (T3).
 */
class InvalidTransferClaimException extends RuntimeException
{
    public static function notPositive(): self
    {
        return new self('The transfer amount must be greater than zero.');
    }

    public static function alreadyPending(): self
    {
        return new self('You already have a transfer awaiting confirmation. Please wait for the council to review it.');
    }

    public static function alreadyDecided(): self
    {
        return new self('This claim has already been decided.');
    }

    public static function exceedsOutstanding(): self
    {
        return new self('The claimed amount is more than the tenant currently owes — reject it, or ask the tenant to resubmit.');
    }
}
