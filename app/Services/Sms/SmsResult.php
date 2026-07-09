<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * The outcome of one SMS send attempt, captured for the notification log
 * (FR-NOT-05, INT-SMS-03).
 */
final class SmsResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $providerId,
        public readonly ?string $error,
    ) {}

    public static function success(?string $providerId = null): self
    {
        return new self(true, $providerId, null);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, $error);
    }
}
