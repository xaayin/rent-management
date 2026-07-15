<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a supervisor-approval request (PRD §6.1 `A` cells). Only
 * Pending requests hold a `pending_key`, which is what enforces "one open
 * request per subject and action" at the database level.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }
}
