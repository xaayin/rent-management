<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ReminderKind;
use App\Models\Receipt;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Notifications\Notification;

/**
 * The "we received your payment" text, sent the moment a receipt is issued —
 * the tenant's proof-in-pocket, quoting the receipt number and their
 * remaining balance. One per receipt, never per invoice.
 */
class PaymentConfirmation extends Notification
{
    public function __construct(
        public readonly Receipt $receipt,
        public readonly string $message,
    ) {}

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
     * Extra columns for the notification log entry (FR-NOT-05). No invoice_id:
     * the confirmation belongs to the receipt, which may span several.
     *
     * @return array<string, mixed>
     */
    public function smsLogContext(): array
    {
        return [
            'kind' => ReminderKind::PaymentConfirmation->value,
        ];
    }
}
