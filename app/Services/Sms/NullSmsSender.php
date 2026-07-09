<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Discards messages while reporting success — for environments where even log
 * noise is unwanted.
 */
class NullSmsSender implements SmsSender
{
    public function send(string $to, string $message): SmsResult
    {
        return SmsResult::success();
    }
}
