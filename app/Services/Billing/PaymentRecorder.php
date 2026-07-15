<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Exceptions\InvalidPaymentException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records payments and reversing entries against invoices (PRD §4.6, §5.4).
 *
 * The fine is recomputed on the actual payment date before allocating
 * (FR-PAY-02), and the payment is split principal-first (rent + charges) then
 * fine, per the central policy in config/billing.php (§5.4.23). Every entry is
 * append-only; a correction is a negated reversal row with a reason
 * (FR-PAY-06).
 */
class PaymentRecorder
{
    public function __construct(
        private readonly InvoiceFineApplier $fineApplier,
        private readonly ReceiptNumberGenerator $receipts,
    ) {}

    public function record(
        Invoice $invoice,
        Money $amount,
        CarbonImmutable $paymentDate,
        PaymentMethod $method,
        ?string $reference = null,
        ?User $recordedBy = null,
    ): Payment {
        if (! $amount->isPositive()) {
            throw InvalidPaymentException::notPositive();
        }

        $paymentDate = $paymentDate->startOfDay();

        return DB::transaction(function () use ($invoice, $amount, $paymentDate, $method, $reference, $recordedBy): Payment {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            // FR-PAY-02: the fine due is the one accrued up to the actual
            // payment date — recomputed now, while principal is outstanding.
            if ($invoice->outstandingPrincipalLaari() > 0) {
                $this->fineApplier->refreshFine($invoice, $paymentDate);
                $invoice->refresh();
            }

            $outstandingPrincipal = max($invoice->outstandingPrincipalLaari(), 0);
            $outstandingFine = max($invoice->outstandingFineLaari(), 0);

            if ($amount->laari > $outstandingPrincipal + $outstandingFine) {
                throw InvalidPaymentException::exceedsOutstanding();
            }

            [$principal, $fine] = $this->allocate($amount->laari, $outstandingPrincipal, $outstandingFine);

            $payment = Payment::create([
                'receipt_number' => $this->receipts->next($paymentDate->year),
                'invoice_id' => $invoice->id,
                'amount_laari' => $amount->laari,
                'principal_allocated_laari' => $principal,
                'fine_allocated_laari' => $fine,
                'payment_date' => $paymentDate->toDateString(),
                'method' => $method->value,
                'reference' => $reference,
                'type' => PaymentType::Payment->value,
                'recorded_by' => $recordedBy?->id,
            ]);

            $invoice->refreshPaymentStatus($paymentDate);

            return $payment;
        });
    }

    /**
     * Correct a wrongly entered payment by appending its negation — the
     * original row is never modified or deleted (FR-PAY-06).
     */
    public function reverse(
        Payment $payment,
        string $reason,
        CarbonImmutable $date,
        ?User $recordedBy = null,
    ): Payment {
        $this->assertReversible($payment);

        return DB::transaction(function () use ($payment, $reason, $date, $recordedBy): Payment {
            $reversal = Payment::create([
                'receipt_number' => null,
                'invoice_id' => $payment->invoice_id,
                'amount_laari' => -$payment->amount_laari,
                'principal_allocated_laari' => -$payment->principal_allocated_laari,
                'fine_allocated_laari' => -$payment->fine_allocated_laari,
                'payment_date' => $date->startOfDay()->toDateString(),
                'method' => $payment->method->value,
                'reference' => $payment->receipt_number,
                'type' => PaymentType::Reversal->value,
                'reversed_payment_id' => $payment->id,
                'reversal_reason' => $reason,
                'recorded_by' => $recordedBy?->id,
            ]);

            $payment->invoice->refreshPaymentStatus($date->startOfDay());

            return $reversal;
        });
    }

    /**
     * Whether this row may be reversed at all. Public because the approvals
     * workflow has to ask the same question before queueing a reversal for a
     * supervisor, and again before carrying it out — one definition, so the two
     * paths can never disagree about what is reversible.
     *
     * @throws InvalidPaymentException
     */
    public function assertReversible(Payment $payment): void
    {
        if ($payment->isReversal()) {
            throw InvalidPaymentException::cannotReverseReversal();
        }

        if ($payment->isReversed()) {
            throw InvalidPaymentException::alreadyReversed();
        }
    }

    /**
     * Split an amount across the outstanding buckets per the central policy.
     *
     * @return array{0: int, 1: int} [principal, fine] in laari
     */
    private function allocate(int $amountLaari, int $outstandingPrincipal, int $outstandingFine): array
    {
        if (config('billing.payment_allocation', 'rent_first') === 'fine_first') {
            $fine = min($amountLaari, $outstandingFine);
            $principal = min($amountLaari - $fine, $outstandingPrincipal);

            return [$principal, $fine];
        }

        // Default: rent (principal) first, then fine (§5.4.23 locked default).
        $principal = min($amountLaari, $outstandingPrincipal);
        $fine = min($amountLaari - $principal, $outstandingFine);

        return [$principal, $fine];
    }
}
