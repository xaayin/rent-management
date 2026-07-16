<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Enums\PaymentMethod;
use App\Enums\TransferClaimStatus;
use App\Exceptions\InvalidPaymentException;
use App\Exceptions\InvalidTransferClaimException;
use App\Models\Receipt;
use App\Models\Tenant;
use App\Models\TransferClaim;
use App\Models\User;
use App\Services\Billing\PaymentRecorder;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The bank-transfer claim workflow (T3).
 *
 * The hardship this addresses is not the fine rate — it's that paying required
 * being physically on the island. A tenant working in Malé transfers the rent
 * to the council's account and submits the reference; Finance confirms it.
 *
 * A claim is a workflow record, not money. Nothing touches the ledger until
 * `confirm()`, which records a real payment through PaymentRecorder — so it
 * inherits everything: oldest-first allocation, the no-credit-balance guard,
 * the append-only receipt, and the T1 confirmation SMS. The claim then points
 * at the receipt it became.
 */
class TransferClaimService
{
    public function __construct(private readonly PaymentRecorder $payments) {}

    /**
     * A tenant files a claim. One pending claim at a time, so the Finance queue
     * can't be flooded and a tenant can't double-submit the same transfer.
     */
    public function submit(
        Tenant $tenant,
        Money $amount,
        CarbonImmutable $transferDate,
        string $bankReference,
        ?string $note = null,
    ): TransferClaim {
        if (! $amount->isPositive()) {
            throw InvalidTransferClaimException::notPositive();
        }

        if ($tenant->transferClaims()->pending()->exists()) {
            throw InvalidTransferClaimException::alreadyPending();
        }

        return TransferClaim::create([
            'tenant_id' => $tenant->id,
            'amount_laari' => $amount->laari,
            'transfer_date' => $transferDate->toDateString(),
            'bank_reference' => $bankReference,
            'note' => $note,
            'status' => TransferClaimStatus::Pending,
        ]);
    }

    /**
     * Finance confirms a claim: record the money as a bank-transfer payment,
     * dated to when the tenant says the transfer happened (so fines compute to
     * that date), and link the claim to the resulting receipt.
     */
    public function confirm(TransferClaim $claim, User $confirmedBy): Receipt
    {
        return DB::transaction(function () use ($claim, $confirmedBy): Receipt {
            /** @var TransferClaim $claim */
            $claim = TransferClaim::query()->lockForUpdate()->findOrFail($claim->getKey());

            if (! $claim->isPending()) {
                throw InvalidTransferClaimException::alreadyDecided();
            }

            try {
                $receipt = $this->payments->recordForTenant(
                    $claim->tenant,
                    $claim->amount(),
                    CarbonImmutable::parse($claim->transfer_date->toDateString()),
                    PaymentMethod::BankTransfer,
                    $claim->bank_reference,
                    $confirmedBy,
                );
            } catch (InvalidPaymentException $e) {
                // The tenant over-claimed, or paid some of it directly since —
                // surface it as a claim error and leave the claim pending.
                throw InvalidTransferClaimException::exceedsOutstanding();
            }

            $claim->update([
                'status' => TransferClaimStatus::Confirmed,
                'receipt_id' => $receipt->id,
                'decided_by' => $confirmedBy->id,
                'decided_at' => now(),
            ]);

            return $receipt;
        });
    }

    /**
     * Finance rejects a claim. The reason is mandatory and shown to the tenant;
     * no money moves.
     */
    public function reject(TransferClaim $claim, User $rejectedBy, string $reason): void
    {
        if (! $claim->isPending()) {
            throw InvalidTransferClaimException::alreadyDecided();
        }

        $claim->update([
            'status' => TransferClaimStatus::Rejected,
            'decided_by' => $rejectedBy->id,
            'decided_at' => now(),
            'decision_note' => $reason,
        ]);
    }

    public function pendingCount(): int
    {
        return TransferClaim::pending()->count();
    }
}
