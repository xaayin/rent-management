<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a tenant's bank-transfer claim (T3): they say "I transferred X,
 * here's the reference", and Finance confirms it into a real payment or
 * rejects it with a reason.
 *
 * A claim is a workflow record, NOT money — nothing moves the ledger until it
 * is confirmed, which records the payment through PaymentRecorder like any
 * other.
 */
enum TransferClaimStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting confirmation',
            self::Confirmed => 'Confirmed',
            self::Rejected => 'Rejected',
        };
    }

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }
}
