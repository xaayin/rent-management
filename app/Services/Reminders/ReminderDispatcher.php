<?php

declare(strict_types=1);

namespace App\Services\Reminders;

use App\Enums\InvoiceStatus;
use App\Enums\NotificationStatus;
use App\Enums\ReminderKind;
use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Models\ReminderRule;
use App\Notifications\PaymentReminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Selects the invoices due a reminder on a given day and sends them (PRD
 * §4.8). Guards: paid invoices are never reminded (FR-NOT-07), opted-out
 * tenants are skipped (§5.5.26), and each (invoice, kind) is sent at most once
 * per cycle (FR-NOT-09) — although failed attempts are retried on the next
 * run. One tenant's error never stops the rest of the run.
 */
class ReminderDispatcher
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    public function dispatchForDate(CarbonImmutable $today): void
    {
        $today = $today->startOfDay();

        foreach (ReminderRule::query()->where('enabled', true)->get() as $rule) {
            $dueDate = match ($rule->kind) {
                ReminderKind::PreDue => $today->addDays($rule->days),
                ReminderKind::OnDue => $today,
                ReminderKind::Overdue => $today->subDays($rule->days),
                ReminderKind::Manual => null,
            };

            if ($dueDate === null) {
                continue;
            }

            Invoice::query()
                ->whereDate('due_date', $dueDate->toDateString())
                ->whereIn('status', [
                    InvoiceStatus::Issued->value,
                    InvoiceStatus::PartlyPaid->value,
                    InvoiceStatus::Overdue->value,
                ])
                ->with(['lease.tenant', 'lease.property'])
                ->each(function (Invoice $invoice) use ($rule): void {
                    try {
                        $this->remind($invoice, $rule->kind, $rule->template);
                    } catch (Throwable $e) {
                        Log::error('Payment reminder failed for invoice.', [
                            'invoice_id' => $invoice->id,
                            'kind' => $rule->kind->value,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });
        }
    }

    /**
     * The staff "send reminder now" action (FR-NOT-08). Uses the overdue
     * template when the invoice is past due, the pre-due one otherwise.
     * Returns the resulting log entry, or null when the send was skipped.
     */
    public function sendManual(Invoice $invoice): ?NotificationLog
    {
        $kind = $invoice->status === InvoiceStatus::Overdue
            ? ReminderKind::Overdue
            : ReminderKind::PreDue;

        $template = ReminderRule::query()->where('kind', $kind->value)->value('template')
            ?? 'Dear {tenant}, invoice {invoice_number} for {property} ({period}) has {amount_due} outstanding. Please pay to {payment_account}.';

        if (! $this->remind($invoice, ReminderKind::Manual, $template)) {
            return null;
        }

        return NotificationLog::query()
            ->where('invoice_id', $invoice->id)
            ->where('kind', ReminderKind::Manual->value)
            ->latest('id')
            ->first();
    }

    /**
     * Send one reminder if every business rule allows it. Returns whether a
     * send was attempted.
     */
    public function remind(Invoice $invoice, ReminderKind $kind, string $template): bool
    {
        $tenant = $invoice->lease->tenant;

        // Never remind on settled invoices (FR-NOT-07).
        if ($invoice->status === InvoiceStatus::Paid || $invoice->outstandingTotalLaari() <= 0) {
            return false;
        }

        // Valid mobile and no opt-out (§5.5.26).
        if (! $tenant->canReceiveSms()) {
            return false;
        }

        // At most one successful send per (invoice, kind) per cycle
        // (FR-NOT-09); manual sends are always allowed.
        if ($kind !== ReminderKind::Manual && $this->alreadySent($invoice, $kind)) {
            return false;
        }

        $tenant->notify(new PaymentReminder(
            $invoice,
            $kind,
            $this->renderer->render($template, $invoice),
        ));

        return true;
    }

    private function alreadySent(Invoice $invoice, ReminderKind $kind): bool
    {
        return NotificationLog::query()
            ->where('invoice_id', $invoice->id)
            ->where('kind', $kind->value)
            ->where('status', NotificationStatus::Sent->value)
            ->exists();
    }
}
