<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A payment (or reversing entry) against an invoice. Strictly append-only:
 * rows can never be updated or deleted after creation, which also guarantees
 * a receipt number can never change after issue (FR-PAY-06/07, hard rule 2).
 */
class Payment extends Model
{
    use LogsActivity;

    protected $fillable = [
        'receipt_id',
        'invoice_id',
        'amount_laari',
        'principal_allocated_laari',
        'fine_allocated_laari',
        'payment_date',
        'method',
        'reference',
        'type',
        'reversed_payment_id',
        'reversal_reason',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_laari' => 'integer',
            'principal_allocated_laari' => 'integer',
            'fine_allocated_laari' => 'integer',
            'payment_date' => 'date',
            'method' => PaymentMethod::class,
            'type' => PaymentType::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Payments are append-only and cannot be edited after issue.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Financial records are never deleted; record a reversal instead.');
        });
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The handover this allocation belongs to. Null on reversal rows, which are
     * corrections rather than money coming in.
     *
     * @return BelongsTo<Receipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    /**
     * The receipt number, which now lives on the receipt so that one handover
     * settling several invoices shares a single number. Kept as an accessor
     * because it reads as a property of the payment everywhere it is used —
     * statements, the ledger, receipt PDFs.
     */
    protected function receiptNumber(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->receipt?->number);
    }

    /** @return HasOne<Payment, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversed_payment_id');
    }

    /** @return BelongsTo<Payment, $this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_payment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isReversal(): bool
    {
        return $this->type === PaymentType::Reversal;
    }

    public function isReversed(): bool
    {
        return $this->reversal()->exists();
    }

    public function amount(): Money
    {
        return Money::fromLaari($this->amount_laari);
    }

    public function principalAllocated(): Money
    {
        return Money::fromLaari($this->principal_allocated_laari);
    }

    public function fineAllocated(): Money
    {
        return Money::fromLaari($this->fine_allocated_laari);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'receipt_id', 'invoice_id', 'amount_laari',
                'principal_allocated_laari', 'fine_allocated_laari',
                'payment_date', 'method', 'type', 'reversed_payment_id', 'reversal_reason',
            ])
            ->dontLogEmptyChanges()
            ->useLogName('payment');
    }
}
