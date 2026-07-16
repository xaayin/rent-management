<?php

declare(strict_types=1);

namespace App\Services\Reminders;

use App\Enums\ReminderKind;
use App\Models\Receipt;
use App\Models\ReminderRule;
use App\Notifications\PaymentConfirmation;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Texts the tenant the moment their money is receipted (T1) — proof in their
 * pocket, without asking the counter for it.
 *
 * Called by PaymentRecorder AFTER its transaction commits: a confirmation for
 * money that rolled back must never leave the building. The inverse also
 * holds — the payment is already committed, so nothing here (a broken
 * template, a down gateway) may throw back into the recording flow. The
 * SmsChannel already logs failures instead of raising; this wraps the rest.
 */
class PaymentConfirmationSender
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    public function send(Receipt $receipt): void
    {
        try {
            $rule = ReminderRule::query()
                ->where('kind', ReminderKind::PaymentConfirmation->value)
                ->where('enabled', true)
                ->first();

            if ($rule === null) {
                return;
            }

            $tenant = $receipt->tenant;

            if (! $tenant->canReceiveSms()) {
                return;
            }

            $tenant->notify(new PaymentConfirmation(
                $receipt,
                $this->renderer->renderReceipt($rule->template, $receipt),
            ));
        } catch (Throwable $e) {
            Log::error('Payment confirmation SMS failed.', [
                'receipt_id' => $receipt->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
