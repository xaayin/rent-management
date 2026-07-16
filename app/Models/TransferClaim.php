<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransferClaimStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A tenant's claim that they paid by bank transfer (T3), awaiting Finance
 * confirmation. State transitions go through TransferClaimService, never this
 * model directly.
 */
class TransferClaim extends Model
{
    use LogsActivity;

    protected $fillable = [
        'tenant_id', 'amount_laari', 'transfer_date', 'bank_reference', 'note',
        'status', 'receipt_id', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'amount_laari' => 'integer',
            'transfer_date' => 'date',
            'status' => TransferClaimStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Receipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @param Builder<$this> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', TransferClaimStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === TransferClaimStatus::Pending;
    }

    public function amount(): Money
    {
        return Money::fromLaari($this->amount_laari);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tenant_id', 'amount_laari', 'bank_reference', 'status', 'receipt_id', 'decided_by', 'decision_note'])
            ->dontLogEmptyChanges()
            ->useLogName('transfer_claim');
    }
}
