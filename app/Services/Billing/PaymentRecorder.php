<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Exceptions\InvalidPaymentException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
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

            $receipt = $this->openReceipt($invoice->lease->tenant_id, $paymentDate, $recordedBy);

            return $this->applyToInvoice($receipt, $invoice, $amount->laari, $paymentDate, $method, $reference, $recordedBy);
        });
    }

    /**
     * Record ONE handover of money from a tenant and spread it across their
     * outstanding invoices, oldest due first.
     *
     * A tenant who walks in and clears five months at once had to be entered
     * five times, and left with five receipt numbers for one payment. Here the
     * clerk records what actually happened — the amount handed over — and the
     * allocation is derived.
     *
     * Oldest-first is the receivables convention, and it is the right default
     * here for a concrete reason: it clears the oldest debt first, which keeps
     * the arrears ageing honest rather than leaving a stale balance behind a
     * freshly-settled one.
     *
     * The ledger shape is unchanged — one append-only row per invoice, so
     * statements, the fine freeze and per-invoice reversal all keep working.
     * What is new is that those rows share a receipt.
     *
     * @param  list<int>|null  $onlyInvoiceIds  Restrict to these invoices (a
     *                                          disputed one can be left out);
     *                                          null means every outstanding one.
     */
    public function recordForTenant(
        Tenant $tenant,
        Money $amount,
        CarbonImmutable $paymentDate,
        PaymentMethod $method,
        ?string $reference = null,
        ?User $recordedBy = null,
        ?array $onlyInvoiceIds = null,
    ): Receipt {
        if (! $amount->isPositive()) {
            throw InvalidPaymentException::notPositive();
        }

        $paymentDate = $paymentDate->startOfDay();

        return DB::transaction(function () use ($tenant, $amount, $paymentDate, $method, $reference, $recordedBy, $onlyInvoiceIds): Receipt {
            $invoices = $this->outstandingForTenant($tenant, $onlyInvoiceIds);

            // Every invoice's fine is brought up to the actual payment date
            // before anything is allocated (FR-PAY-02) — the total owed today
            // is what the amount is checked against.
            $totalOutstanding = 0;

            foreach ($invoices as $invoice) {
                if ($invoice->outstandingPrincipalLaari() > 0) {
                    $this->fineApplier->refreshFine($invoice, $paymentDate);
                    $invoice->refresh();
                }

                $totalOutstanding += max($invoice->outstandingTotalLaari(), 0);
            }

            // No credit balances (§5.4) — the same rule as a single payment,
            // just summed across the selected invoices.
            if ($amount->laari > $totalOutstanding) {
                throw InvalidPaymentException::exceedsOutstanding();
            }

            $receipt = $this->openReceipt($tenant->id, $paymentDate, $recordedBy);
            $remaining = $amount->laari;

            foreach ($invoices as $invoice) {
                if ($remaining <= 0) {
                    break;   // the money ran out; later invoices stay untouched
                }

                $due = max($invoice->outstandingTotalLaari(), 0);

                if ($due <= 0) {
                    continue;
                }

                $slice = min($remaining, $due);

                $this->applyToInvoice($receipt, $invoice, $slice, $paymentDate, $method, $reference, $recordedBy);

                $remaining -= $slice;
            }

            return $receipt->load('payments');
        });
    }

    /**
     * The tenant's unsettled invoices, oldest due first — the order the money
     * is applied in. Locked, so a concurrent single payment cannot settle one
     * of them underneath this allocation.
     *
     * @param  list<int>|null  $onlyInvoiceIds
     * @return Collection<int, Invoice>
     */
    private function outstandingForTenant(Tenant $tenant, ?array $onlyInvoiceIds): Collection
    {
        return Invoice::query()
            ->whereHas('lease', fn ($query) => $query->where('tenant_id', $tenant->id))
            ->whereIn('status', [
                InvoiceStatus::Issued->value,
                InvoiceStatus::PartlyPaid->value,
                InvoiceStatus::Overdue->value,
            ])
            ->when($onlyInvoiceIds !== null, fn ($query) => $query->whereIn('id', $onlyInvoiceIds))
            ->orderBy('due_date')
            ->orderBy('id')          // deterministic when two fall due the same day
            ->lockForUpdate()
            ->get();
    }

    /**
     * Take the next receipt number for this handover. One per handover — the
     * number now lives on the receipt, so the rows written against each invoice
     * all carry it.
     */
    private function openReceipt(int $tenantId, CarbonImmutable $paymentDate, ?User $recordedBy): Receipt
    {
        return Receipt::create([
            'number' => $this->receipts->next($paymentDate->year),
            'tenant_id' => $tenantId,
            'recorded_by' => $recordedBy?->id,
        ]);
    }

    /**
     * Write one invoice's slice of a receipt: rent (principal incl. CSR) first,
     * then fine (§5.4). The caller has already refreshed the fine and capped
     * the slice, so this never allocates more than is owed.
     */
    private function applyToInvoice(
        Receipt $receipt,
        Invoice $invoice,
        int $laari,
        CarbonImmutable $paymentDate,
        PaymentMethod $method,
        ?string $reference,
        ?User $recordedBy,
    ): Payment {
        $outstandingPrincipal = max($invoice->outstandingPrincipalLaari(), 0);
        $outstandingFine = max($invoice->outstandingFineLaari(), 0);

        if ($laari > $outstandingPrincipal + $outstandingFine) {
            throw InvalidPaymentException::exceedsOutstanding();
        }

        [$principal, $fine] = $this->allocate($laari, $outstandingPrincipal, $outstandingFine);

        $payment = Payment::create([
            'receipt_id' => $receipt->id,
            'invoice_id' => $invoice->id,
            'amount_laari' => $laari,
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
                'receipt_id' => null,
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
