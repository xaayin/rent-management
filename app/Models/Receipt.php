<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One handover of money from a tenant, identified by a single `YYYY/NNN`
 * number (FR-PAY-01) and allocated across one or more invoices.
 *
 * Append-only, exactly like the payment rows it groups: a receipt number can
 * never change or be reused after issue (FR-PAY-07, hard rule 2). Corrections
 * are reversals of the individual allocation rows, which is what lets a
 * supervisor unwind the money that went to ONE invoice without disturbing the
 * rest of the receipt.
 */
class Receipt extends Model
{
    use LogsActivity;

    protected $fillable = ['number', 'tenant_id', 'recorded_by'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Receipts are append-only and cannot be edited after issue.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Financial records are never deleted; record a reversal instead.');
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * What the tenant actually handed over — the sum of the allocations, which
     * is the figure printed on the receipt.
     */
    public function total(): Money
    {
        return Money::fromLaari((int) $this->payments->sum('amount_laari'));
    }

    /**
     * True when this receipt settled more than one invoice, so the PDF and the
     * ledger can itemise the split rather than implying a single charge.
     */
    public function isSplit(): bool
    {
        return $this->payments->count() > 1;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'tenant_id', 'recorded_by'])
            ->dontLogEmptyChanges()
            ->useLogName('payment');
    }
}
