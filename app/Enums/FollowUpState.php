<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a tenant in arrears sits in the chasing worklist. The order of the
 * cases IS the priority order of the queue: a broken promise outranks someone
 * nobody has called, which outranks a call that has gone cold.
 */
enum FollowUpState: string
{
    case Broken = 'broken';
    case NeverContacted = 'never_contacted';
    case Stale = 'stale';
    case Recent = 'recent';
    case Promised = 'promised';

    public function label(): string
    {
        return match ($this) {
            self::Broken => 'Promise broken',
            self::NeverContacted => 'Not contacted',
            self::Stale => 'Follow up',
            self::Recent => 'Contacted',
            self::Promised => 'Promised',
        };
    }

    public function lozenge(): string
    {
        return match ($this) {
            self::Broken => 'loz-danger',
            self::NeverContacted => 'loz-warning',
            self::Stale => 'loz-warning',
            self::Recent => 'loz-neutral',
            self::Promised => 'loz-info',
        };
    }

    /** Sort weight — lower is more urgent. */
    public function priority(): int
    {
        return match ($this) {
            self::Broken => 0,
            self::NeverContacted => 1,
            self::Stale => 2,
            self::Recent => 3,
            self::Promised => 4,
        };
    }

    /** Is this tenant on today's worklist, or parked? */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Broken, self::NeverContacted, self::Stale], true);
    }
}
