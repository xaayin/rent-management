<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An approval request that violates the workflow rules (PRD §6.1 `A` cells).
 */
class InvalidApprovalException extends RuntimeException
{
    public static function alreadyPending(): self
    {
        return new self('This action is already waiting for supervisor approval.');
    }

    public static function alreadyDecided(): self
    {
        return new self('This request has already been decided.');
    }

    public static function doesNotRequireApproval(): self
    {
        return new self('This user may perform the action directly and does not need approval.');
    }

    /**
     * The subject moved on between the request and the decision — the lease was
     * terminated by someone else, the payment already reversed. Approving would
     * be a no-op at best and a double entry at worst, so it is refused and the
     * reason surfaced to the approver.
     */
    public static function subjectNoLongerActionable(string $why): self
    {
        return new self($why);
    }
}
