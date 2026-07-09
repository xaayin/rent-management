<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The outcome recorded for each attempted send (FR-NOT-05/06). Failed sends
 * are retried by the next scheduled run.
 */
enum NotificationStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Sent',
            self::Failed => 'Failed',
        };
    }
}
