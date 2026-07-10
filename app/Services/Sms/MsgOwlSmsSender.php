<?php

declare(strict_types=1);

namespace App\Services\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * MsgOwl gateway adapter (https://www.msgowl.com/docs — INT-SMS-01/03/04).
 *
 *   POST {endpoint}/messages
 *   Authorization: AccessKey {api_key}
 *   { "recipients": "9607771234", "sender_id": "COUNCIL", "body": "…" }
 *
 * Success is 201 with a message id, captured as the provider id in the
 * notification log. Transient failures (connection errors, 429 rate limits)
 * are retried with back-off; hard failures come back as SmsResult::failure so
 * the channel records them and the next scheduled run retries (FR-NOT-06).
 */
class MsgOwlSmsSender implements SmsSender
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $apiKey,
        private readonly string $senderId,
    ) {}

    public function send(string $to, string $message): SmsResult
    {
        if ($this->apiKey === '') {
            return SmsResult::failure('MsgOwl API key is not configured (SMS_GATEWAY_KEY).');
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'AccessKey '.$this->apiKey])
                ->acceptJson()
                ->timeout(15)
                ->retry(
                    3,
                    1000,
                    fn (Throwable $exception): bool => $exception instanceof ConnectionException
                        || ($exception instanceof RequestException && $exception->response->status() === 429),
                    throw: false,
                )
                ->post(rtrim($this->endpoint, '/').'/messages', [
                    'recipients' => $this->normalise($to),
                    'sender_id' => $this->senderId,
                    'body' => $message,
                ]);
        } catch (Throwable $e) {
            return SmsResult::failure($e->getMessage());
        }

        if ($response->successful()) {
            return SmsResult::success((string) $response->json('id'));
        }

        return SmsResult::failure(
            'MsgOwl error ('.$response->status().'): '
            .(is_string($response->json('message')) ? $response->json('message') : $response->body()),
        );
    }

    /**
     * MsgOwl expects bare digits — "+960 777-1234" becomes "9607771234".
     */
    private function normalise(string $to): string
    {
        return (string) preg_replace('/\D+/', '', $to);
    }
}
