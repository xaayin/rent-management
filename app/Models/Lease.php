<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\CsrType;
use App\Enums\LeaseStatus;
use App\Enums\RentBasis;
use App\Exceptions\ParcelAlreadyLeasedException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\LeaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Lease extends Model
{
    /** @use HasFactory<LeaseFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'agreement_number',
        'property_id',
        'tenant_id',
        'agreement_date',
        'start_date',
        'rent_start_date',
        'duration_years',
        'expiry_date',
        'rent_basis',
        'rate_laari',
        'area_sqft',
        'flat_amount_laari',
        'grace_months',
        'billing_cycle',
        'due_day',
        'csr_type',
        'csr_amount_laari',
        'csr_percent_bps',
        'csr_declared_revenue_laari',
        'csr_month',
        'security_deposit_laari',
        'status',
        'terminated_on',
        'termination_reason',
        'notes',
    ];

    /**
     * In-memory defaults mirroring the migration defaults, so freshly created
     * models (before a DB reload) report the right charge configuration.
     */
    protected $attributes = [
        'grace_months' => 0,
        'billing_cycle' => 'monthly',
        'due_day' => 10,
        'csr_type' => 'none',
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'agreement_date' => 'date',
            'start_date' => 'date',
            'rent_start_date' => 'date',
            'expiry_date' => 'date',
            'terminated_on' => 'date',
            'duration_years' => 'integer',
            'rate_laari' => 'integer',
            'area_sqft' => 'integer',
            'flat_amount_laari' => 'integer',
            'grace_months' => 'integer',
            'due_day' => 'integer',
            'csr_amount_laari' => 'integer',
            'csr_percent_bps' => 'integer',
            'csr_declared_revenue_laari' => 'integer',
            'csr_month' => 'integer',
            'security_deposit_laari' => 'integer',
            'rent_basis' => RentBasis::class,
            'billing_cycle' => BillingCycle::class,
            'csr_type' => CsrType::class,
            'status' => LeaseStatus::class,
        ];
    }

    protected static function booted(): void
    {
        // FR-PRP-02: a parcel may not carry two active leases at once. Enforced
        // at the persistence layer so it holds regardless of entry point.
        static::saving(function (Lease $lease): void {
            if ($lease->status === LeaseStatus::Active && $lease->hasActiveParcelConflict()) {
                throw ParcelAlreadyLeasedException::forProperty((int) $lease->property_id);
            }
        });
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<FineRule, $this> */
    public function fineRules(): HasMany
    {
        return $this->hasMany(FineRule::class);
    }

    /** @return HasManyThrough<Payment, Invoice, $this> */
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Invoice::class);
    }

    /**
     * The fine rule in force on a given date (FR-FIN-09): the rule whose period
     * contains it, both ends inclusive. Null — a date before the first period,
     * or in a gap between periods — means no fine accrues.
     *
     * Periods never overlap when written through FineRuleScheduler, so at most
     * one row matches. The ordering is the tiebreak for rows written outside it
     * (the importer, seeders and pre-period data all leave the end open): among
     * open-ended rules the latest still supersedes, as it always did.
     */
    public function fineRuleOn(CarbonImmutable $date): ?FineRule
    {
        return $this->fineRules()
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(fn ($q) => $q
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date->toDateString()))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    public function currentFineRule(): ?FineRule
    {
        return $this->fineRuleOn(CarbonImmutable::parse(today()->toDateString()));
    }

    /**
     * The date from which rent is actually charged: the rent-start date pushed
     * out by the grace period (FR-CHG-03).
     */
    public function effectiveRentStart(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->rent_start_date)->addMonths((int) $this->grace_months);
    }

    public function hasCsr(): bool
    {
        return $this->csr_type !== CsrType::None;
    }

    /**
     * The calendar month (1–12) in which the annual CSR charge falls. Defaults
     * to the rent-start month when not explicitly configured.
     */
    public function effectiveCsrMonth(): int
    {
        return $this->csr_month ?? CarbonImmutable::parse($this->rent_start_date)->month;
    }

    /**
     * The annual CSR amount (FR-CHG-04): a fixed amount, or a percentage of the
     * declared revenue. Basis points keep the percentage calculation in integers.
     */
    public function csrAnnualAmount(): Money
    {
        return match ($this->csr_type) {
            CsrType::FixedAnnual => Money::fromLaari((int) $this->csr_amount_laari),
            CsrType::PercentOfRevenue => Money::fromLaari(
                intdiv((int) $this->csr_declared_revenue_laari * (int) $this->csr_percent_bps + 5000, 10000),
            ),
            CsrType::None => Money::fromLaari(0),
        };
    }

    /** @param  Builder<Lease>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', LeaseStatus::Active->value);
    }

    /** @param  Builder<Lease>  $query */
    public function scopeExpired(Builder $query): void
    {
        $query->where('status', LeaseStatus::Expired->value);
    }

    public function isActive(): bool
    {
        return $this->status === LeaseStatus::Active;
    }

    /**
     * Whether the lease's expiry date is in the past (app timezone).
     */
    public function isPastExpiry(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->lessThan(today());
    }

    public function hasActiveParcelConflict(): bool
    {
        return static::query()
            ->where('property_id', $this->property_id)
            ->where('status', LeaseStatus::Active->value)
            ->when($this->exists, fn (Builder $query) => $query->whereKeyNot($this->getKey()))
            ->exists();
    }

    /**
     * The base monthly rent from the rent basis (FR-CHG-01). Grace periods and
     * CSR charges are layered on at invoice time (Slice 3).
     */
    public function monthlyRent(): Money
    {
        return match ($this->rent_basis) {
            RentBasis::PerSquareFoot => Money::fromLaari((int) $this->rate_laari * (int) $this->area_sqft),
            RentBasis::Flat => Money::fromLaari((int) $this->flat_amount_laari),
        };
    }

    public function terminate(string $reason): void
    {
        $this->update([
            'status' => LeaseStatus::Terminated,
            'terminated_on' => today(),
            'termination_reason' => $reason,
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'agreement_number', 'property_id', 'tenant_id', 'status',
                'rent_basis', 'rate_laari', 'area_sqft', 'flat_amount_laari',
                'start_date', 'rent_start_date', 'expiry_date', 'terminated_on', 'termination_reason',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('lease');
    }
}
