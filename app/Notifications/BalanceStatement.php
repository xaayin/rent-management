<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ReminderKind;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Notifications\Notification;

/**
 * The monthly "your balance with the council is X" text for tenants in
 * arrears — a statement, not a demand; the reminder kinds handle urgency.
 */
class BalanceStatement extends Notification
{
    public function __construct(public readonly string $message) {}

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [SmsChannel::class];
    }

    public function toSms(object $notifiable): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function smsLogContext(): array
    {
        return [
            'kind' => ReminderKind::BalanceStatement->value,
        ];
    }
}
