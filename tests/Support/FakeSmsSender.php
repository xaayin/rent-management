<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Sms\SmsResult;
use App\Services\Sms\SmsSender;

/**
 * Records every send for assertions; can be told to fail like a broken
 * gateway.
 */
class FakeSmsSender implements SmsSender
{
    /** @var list<array{to: string, message: string}> */
    public array $sent = [];

    public bool $shouldFail = false;

    public function send(string $to, string $message): SmsResult
    {
        $this->sent[] = ['to' => $to, 'message' => $message];

        return $this->shouldFail
            ? SmsResult::failure('gateway unreachable')
            : SmsResult::success('fake-'.count($this->sent));
    }
}
