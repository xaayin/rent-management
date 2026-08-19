<?php

declare(strict_types=1);

namespace App\Enums;

/** How the council reached (or tried to reach) the tenant. */
enum ContactChannel: string
{
    case Call = 'call';
    case Sms = 'sms';
    case Visit = 'visit';
    case Letter = 'letter';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Phone call',
            self::Sms => 'SMS',
            self::Visit => 'Visit',
            self::Letter => 'Letter',
            self::Other => 'Other',
        };
    }
}
