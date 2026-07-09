<?php

declare(strict_types=1);

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Development driver: writes each SMS to the application log instead of a
 * gateway, and reports success so the full reminder pipeline can be exercised
 * before the council supplies its provider.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): SmsResult
    {
        Log::info('SMS (log driver)', ['to' => $to, 'message' => $message]);

        return SmsResult::success();
    }
}
