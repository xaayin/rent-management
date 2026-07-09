<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FineBase;
use App\Enums\FineMethod;
use App\Support\Money;
use Database\Factories\FineRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A lease's late-fine configuration (FR-FIN-01/02/03), effective-dated so a
 * rule change never rewrites how historic invoices were fined (FR-FIN-09).
 * Rules are append-only: a change is a new row with a later effective_from.
 */
class FineRule extends Model
{
    /** @use HasFactory<FineRuleFactory> */
    use HasFactory, LogsActivity;

    /** Locked defaults for the tiered method (FR-FIN-10): MVR 100 / MVR 50. */
    public const int DEFAULT_FIRST_MONTH_LAARI = 10_000;

    public const int DEFAULT_SUBSEQUENT_MONTH_LAARI = 5_000;

    protected $fillable = [
        'lease_id',
        'method',
        'base',
        'allowance_days',
        'flat_daily_laari',
        'percent_daily_bps',
        'first_month_laari',
        'subsequent_month_laari',
        'cap_laari',
        'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'method' => FineMethod::class,
            'base' => FineBase::class,
            'allowance_days' => 'integer',
            'flat_daily_laari' => 'integer',
            'percent_daily_bps' => 'integer',
            'first_month_laari' => 'integer',
            'subsequent_month_laari' => 'integer',
            'cap_laari' => 'integer',
            'effective_from' => 'date',
        ];
    }

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function firstMonthLaari(): int
    {
        return $this->first_month_laari ?? self::DEFAULT_FIRST_MONTH_LAARI;
    }

    public function subsequentMonthLaari(): int
    {
        return $this->subsequent_month_laari ?? self::DEFAULT_SUBSEQUENT_MONTH_LAARI;
    }

    /**
     * One-line human summary for lists and the fine-rule panel.
     */
    public function summary(): string
    {
        return match ($this->method) {
            FineMethod::FlatPerDay => Money::fromLaari((int) $this->flat_daily_laari)->format().' per day late',
            FineMethod::PercentPerDay => rtrim(rtrim(number_format($this->percent_daily_bps / 100, 2, '.', ''), '0'), '.')
                .'%/day of '.$this->base->label(),
            FineMethod::TieredMonthly => 'Tiered — first month '.Money::fromLaari($this->firstMonthLaari())->format()
                .', then '.Money::fromLaari($this->subsequentMonthLaari())->format().' per month',
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'lease_id', 'method', 'base', 'allowance_days',
                'flat_daily_laari', 'percent_daily_bps',
                'first_month_laari', 'subsequent_month_laari',
                'cap_laari', 'effective_from',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('fine_rule');
    }
}
