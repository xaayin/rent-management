<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Services\Billing\FineRuleResolver;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A generated demand for payment covering one billing cycle (FR-INV-01).
 * Financial records are append-only — invoices are never hard-deleted.
 */
class Invoice extends Model
{
    use LogsActivity;

    protected $fillable = [
        'number',
        'lease_id',
        'period_year',
        'period_month',
        'period_months',
        'period_start',
        'period_end',
        'due_date',
        'status',
        'cancelled_at',
        'cancellation_reason',
        'cancelled_by',
        'period_key',
        'rent_laari',
        'charges_laari',
        'fine_laari',
        'fine_rule_id',
        'total_laari',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'period_months' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'due_date' => 'date',
            'cancelled_at' => 'datetime',
            'status' => InvoiceStatus::class,
            'rent_laari' => 'integer',
            'charges_laari' => 'integer',
            'fine_laari' => 'integer',
            'total_laari' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Financial records are never hard-deleted (FR-AUD-02, hard rule 2).
        static::deleting(function (): never {
            throw new RuntimeException('Invoices are never deleted; corrections are reversing entries.');
        });

        /*
         * `period_key` carries the one-live-invoice-per-lease-per-month rule at
         * the database level. Voiding releases it (NULLs never collide), which
         * is what frees the month for a corrected invoice. Deriving it here
         * rather than at the call sites means it can never be forgotten.
         */
        static::saving(function (Invoice $invoice): void {
            $invoice->period_key = $invoice->status === InvoiceStatus::Cancelled
                ? null
                : $invoice->lease_id.'-'.$invoice->period_year.'-'.$invoice->period_month;
        });
    }

    public function isCancelled(): bool
    {
        return $this->status === InvoiceStatus::Cancelled;
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * The fine period that produced this invoice's current fine.
     *
     * @return BelongsTo<FineRule, $this>
     */
    public function fineRule(): BelongsTo
    {
        return $this->belongsTo(FineRule::class);
    }

    /**
     * The period governing this invoice — resolved live rather than read from
     * fine_rule_id, so the answer is right even before the invoice has ever
     * been fined (an invoice not yet past due carries no fine at all).
     */
    public function governingFineRule(): ?FineRule
    {
        return app(FineRuleResolver::class)->for($this);
    }

    /** @return HasMany<InvoiceLineItem, $this> */
    public function lineItems(): HasMany
    {
        return $this->hasMany(InvoiceLineItem::class)->orderBy('position');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The non-fine amount owed for the cycle: rent plus CSR charges.
     */
    public function principalLaari(): int
    {
        return $this->rent_laari + $this->charges_laari;
    }

    /**
     * Reversals are negative rows, so plain sums net them out automatically.
     */
    public function paidPrincipalLaari(): int
    {
        return (int) $this->payments()->sum('principal_allocated_laari');
    }

    public function paidFineLaari(): int
    {
        return (int) $this->payments()->sum('fine_allocated_laari');
    }

    public function outstandingPrincipalLaari(): int
    {
        return $this->principalLaari() - $this->paidPrincipalLaari();
    }

    public function outstandingFineLaari(): int
    {
        return $this->fine_laari - $this->paidFineLaari();
    }

    public function outstandingTotalLaari(): int
    {
        // A voided invoice owes nothing, whichever caller asks — the recorded
        // rent and fine stay on the row as a record of what was raised.
        if ($this->isCancelled()) {
            return 0;
        }

        return $this->outstandingPrincipalLaari() + $this->outstandingFineLaari();
    }

    public function outstandingTotal(): Money
    {
        return Money::fromLaari($this->outstandingTotalLaari());
    }

    /**
     * Derive Paid / Partly paid / Overdue / Issued from the payment ledger and
     * the due date (FR-PAY-04, §5.4.25 — Paid only when rent AND due fine are
     * fully settled).
     */
    public function refreshPaymentStatus(CarbonImmutable $asOf): void
    {
        if ($this->isCancelled()) {
            return; // a voided invoice never comes back to life
        }

        $paidAnything = (int) $this->payments()->sum('amount_laari') > 0;

        $status = match (true) {
            $this->outstandingTotalLaari() <= 0 => InvoiceStatus::Paid,
            $paidAnything => InvoiceStatus::PartlyPaid,
            $asOf->startOfDay()->greaterThan(CarbonImmutable::parse($this->due_date->toDateString())) => InvoiceStatus::Overdue,
            default => InvoiceStatus::Issued,
        };

        $this->update(['status' => $status->value]);
    }

    /**
     * "Jan 2026" for a monthly invoice, "Jan – Jun 2026" for an advance one.
     */
    public function periodLabel(): string
    {
        if ($this->period_months <= 1) {
            return $this->period_start->format('M Y');
        }

        return $this->period_start->format('M Y').' – '.$this->period_end->format('M Y');
    }

    public function rent(): Money
    {
        return Money::fromLaari($this->rent_laari);
    }

    public function charges(): Money
    {
        return Money::fromLaari($this->charges_laari);
    }

    public function fine(): Money
    {
        return Money::fromLaari($this->fine_laari);
    }

    public function total(): Money
    {
        return Money::fromLaari($this->total_laari);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'lease_id', 'status', 'rent_laari', 'charges_laari', 'fine_laari', 'total_laari'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('invoice');
    }
}
