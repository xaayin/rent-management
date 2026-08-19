<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a promise to pay turned out. Derived from the payment ledger on every
 * read — never stored, so nobody has to remember to tick it off.
 */
enum PromiseOutcome: string
{
    /** The contact was a note only; no date was promised. */
    case None = 'none';

    /** Promised for today or later, and not yet covered by payments. */
    case Open = 'open';

    /** Payments since the contact cover what was promised. */
    case Kept = 'kept';

    /** The promised date passed and the money did not arrive. */
    case Broken = 'broken';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No promise',
            self::Open => 'Promised',
            self::Kept => 'Promise kept',
            self::Broken => 'Promise broken',
        };
    }

    public function lozenge(): string
    {
        return match ($this) {
            self::None => 'loz-neutral',
            self::Open => 'loz-info',
            self::Kept => 'loz-success',
            self::Broken => 'loz-danger',
        };
    }
}
