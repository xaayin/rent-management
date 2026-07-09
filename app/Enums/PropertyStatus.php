<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Property lifecycle (FR-PRP-01 "… and archive property records").
 */
enum PropertyStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }
}
