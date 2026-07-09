<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use App\Services\Sms\SmsSender;
use Illuminate\Notifications\Notification;

/**
 * Custom notification channel that delivers through the pluggable SmsSender
 * (FR-NOT-01/10) and records EVERY attempt — success or failure — in the
 * notification log (FR-NOT-05, §5.5.28). Failures are recorded, not thrown,
 * so a bad number never aborts a reminder run; failed sends are retried by
 * the next scheduled cycle (FR-NOT-06).
 */
class SmsChannel
{
    public function __construct(private readonly SmsSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $to = (string) $notifiable->routeNotificationFor('sms', $notification);
        $message = (string) $notification->toSms($notifiable);

        $result = $this->sender->send($to, $message);

        $context = method_exists($notification, 'smsLogContext')
            ? $notification->smsLogContext()
            : [];

        NotificationLog::create(array_merge([
            'tenant_id' => $notifiable->getKey(),
            'channel' => 'sms',
            'recipient' => $to,
            'content' => $message,
            'status' => ($result->success ? NotificationStatus::Sent : NotificationStatus::Failed)->value,
            'provider_id' => $result->providerId,
            'error' => $result->error,
        ], $context));
    }
}
