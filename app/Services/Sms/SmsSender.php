<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * The provider-adapter seam for outbound SMS (FR-NOT-10, PRD §8.1). The real
 * council gateway implements this interface when its API details arrive; until
 * then the log/null drivers stand in.
 */
interface SmsSender
{
    public function send(string $to, string $message): SmsResult;
}
