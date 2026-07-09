<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ReminderKind;
use App\Models\Invoice;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Notifications\Notification;

/**
 * A rent payment reminder to a tenant (PRD §4.8), delivered through the custom
 * SMS channel. The message text is rendered from the editable rule template
 * before construction (FR-NOT-03).
 */
class PaymentReminder extends Notification
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly ReminderKind $kind,
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
     * Extra columns for the notification log entry (FR-NOT-05).
     *
     * @return array<string, mixed>
     */
    public function smsLogContext(): array
    {
        return [
            'invoice_id' => $this->invoice->id,
            'kind' => $this->kind->value,
        ];
    }
}
