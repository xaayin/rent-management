<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvalidInvoiceCancellationException;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Voids an invoice raised in error (FR-INV, hard rule 2).
 *
 * Nothing is deleted: the row and its YYYY/NNN number stay, because a gap in a
 * government numbering sequence is something no auditor can reconcile. What
 * changes is that the charge stops counting — every balance, statement,
 * reminder and report filters on status, so a Cancelled invoice simply is not
 * money any more. Releasing `period_key` also frees the month, so the corrected
 * invoice can be raised in its place.
 */
class InvoiceCanceller
{
    public function cancel(Invoice $invoice, string $reason, ?User $by = null): Invoice
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw InvalidInvoiceCancellationException::reasonRequired();
        }

        $this->assertCancellable($invoice);

        return DB::transaction(function () use ($invoice, $reason, $by): Invoice {
            // Re-check under lock: a payment could have landed since the button
            // was drawn, and voiding a settled invoice would erase real money.
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->assertCancellable($locked);

            $locked->update([
                'status' => InvoiceStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'cancelled_by' => $by?->id,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Whether this invoice may be voided at all. Public so the screen can ask
     * the same question it will be judged by, rather than guessing.
     *
     * @throws InvalidInvoiceCancellationException
     */
    public function assertCancellable(Invoice $invoice): void
    {
        if ($invoice->isCancelled()) {
            throw InvalidInvoiceCancellationException::alreadyCancelled($invoice->number);
        }

        // Reversed payments net to zero and leave the invoice genuinely unpaid,
        // so they do not block the void — only money still standing does.
        if ((int) $invoice->payments()->sum('amount_laari') !== 0) {
            throw InvalidInvoiceCancellationException::hasPayments($invoice->number);
        }
    }

    public function canCancel(Invoice $invoice): bool
    {
        try {
            $this->assertCancellable($invoice);
        } catch (InvalidInvoiceCancellationException) {
            return false;
        }

        return true;
    }
}
