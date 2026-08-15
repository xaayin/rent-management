<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FineBase;
use App\Enums\FineMethod;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\FineRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A lease's late-fine configuration (FR-FIN-01/02/03) for one PERIOD of time,
 * so a rule change never rewrites how historic invoices were fined (FR-FIN-09).
 *
 * The period is [effective_from, effective_to] with both ends inclusive; a null
 * end means "and onwards". Periods on one lease never overlap (enforced by
 * FineRuleScheduler), and a date falling in a gap between periods accrues no
 * fine — that is how the council grants a fine holiday.
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
        'effective_to',
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
            'effective_to' => 'date',
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

    public function isOpenEnded(): bool
    {
        return $this->effective_to === null;
    }

    /** Does this period contain the given day? Both ends are inclusive. */
    public function covers(CarbonImmutable $date): bool
    {
        $day = $date->startOfDay();

        return ! $day->lessThan(CarbonImmutable::parse($this->effective_from->toDateString()))
            && ($this->isOpenEnded() || ! $day->greaterThan(CarbonImmutable::parse($this->effective_to->toDateString())));
    }

    /** "1 Jan 2026 – 31 Mar 2026" or "1 Jul 2026 onwards". */
    public function periodLabel(): string
    {
        if ($this->effective_from === null) {
            return 'Not yet scheduled'; // an unsaved draft being previewed
        }

        $from = $this->effective_from->format('j M Y');

        return $this->isOpenEnded()
            ? $from.' onwards'
            : $from.' – '.$this->effective_to->format('j M Y');
    }

    /** scheduled (not started) | active (contains today) | ended. */
    public function statusOn(CarbonImmutable $today): string
    {
        if ($today->startOfDay()->lessThan(CarbonImmutable::parse($this->effective_from->toDateString()))) {
            return 'scheduled';
        }

        return $this->covers($today) ? 'active' : 'ended';
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
                'cap_laari', 'effective_from', 'effective_to',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('fine_rule');
    }
}
