<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\FineMethod;
use App\Models\FineRule;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Computes the late fine for one overdue amount under a lease's FineRule
 * (PRD §4.7, business rules §5.3). Pure integer-laari arithmetic; the FineRule
 * model is used only as a parameter holder.
 *
 * Late days run from the day after the (allowance-shifted) due date up to and
 * including the as-of date (§5.3.15). If late days ≤ 0 the fine is zero.
 */
class FineCalculator
{
    public function calculate(FineRule $rule, Money $base, CarbonImmutable $dueDate, CarbonImmutable $asOf): FineBreakdown
    {
        // All late-day/month maths is calendar-day granular (§5.3.15): a time
        // of day must never tip an exact-month boundary into the next month.
        $dueDate = $dueDate->startOfDay();
        $asOf = $asOf->startOfDay();

        $allowanceDays = (int) $rule->allowance_days;
        $effectiveDue = $dueDate->addDays($allowanceDays);
        $lateDays = (int) $effectiveDue->diffInDays($asOf, false);

        if ($lateDays <= 0) {
            return FineBreakdown::none(
                $rule->method,
                $base->laari,
                $dueDate->toDateString(),
                $allowanceDays,
                $asOf->toDateString(),
            )->withRule($rule);
        }

        return (match ($rule->method) {
            FineMethod::FlatPerDay => $this->flatPerDay($rule, $base, $dueDate, $asOf, $lateDays),
            FineMethod::PercentPerDay => $this->percentPerDay($rule, $base, $dueDate, $asOf, $lateDays),
            FineMethod::TieredMonthly => $this->tieredMonthly($rule, $base, $dueDate, $effectiveDue, $asOf, $lateDays),
        })->withRule($rule);
    }

    /**
     * Fine = late days × flat daily amount (§5.3.17).
     */
    private function flatPerDay(FineRule $rule, Money $base, CarbonImmutable $due, CarbonImmutable $asOf, int $lateDays): FineBreakdown
    {
        $daily = (int) $rule->flat_daily_laari;
        [$total, $capped] = $this->applyCap($rule, $lateDays * $daily);

        return new FineBreakdown(
            method: FineMethod::FlatPerDay,
            baseLaari: $base->laari,
            dueDate: $due->toDateString(),
            allowanceDays: (int) $rule->allowance_days,
            asOf: $asOf->toDateString(),
            lateDays: $lateDays,
            overdueMonths: null,
            dailyLaari: $daily,
            percentBps: null,
            tierLines: [],
            capLaari: $rule->cap_laari,
            capped: $capped,
            totalLaari: $total,
        );
    }

    /**
     * Daily fine = percentage × base; fine = late days × daily (§5.3.18).
     * The daily amount is rounded half-up to whole laari (central 2 dp rule,
     * FR-FIN-08) — 0.5% of MVR 500.00 is exactly MVR 2.50/day.
     */
    private function percentPerDay(FineRule $rule, Money $base, CarbonImmutable $due, CarbonImmutable $asOf, int $lateDays): FineBreakdown
    {
        $bps = (int) $rule->percent_daily_bps;
        $daily = intdiv($base->laari * $bps + 5_000, 10_000);
        [$total, $capped] = $this->applyCap($rule, $lateDays * $daily);

        return new FineBreakdown(
            method: FineMethod::PercentPerDay,
            baseLaari: $base->laari,
            dueDate: $due->toDateString(),
            allowanceDays: (int) $rule->allowance_days,
            asOf: $asOf->toDateString(),
            lateDays: $lateDays,
            overdueMonths: null,
            dailyLaari: $daily,
            percentBps: $bps,
            tierLines: [],
            capLaari: $rule->cap_laari,
            capped: $capped,
            totalLaari: $total,
        );
    }

    /**
     * Fine = first-month amount + (n − 1) × subsequent-month amount for n
     * overdue months; each commenced month counts as one (§5.3.19, FR-FIN-10).
     */
    private function tieredMonthly(FineRule $rule, Money $base, CarbonImmutable $due, CarbonImmutable $effectiveDue, CarbonImmutable $asOf, int $lateDays): FineBreakdown
    {
        $months = $this->commencedOverdueMonths($effectiveDue, $asOf);
        $first = $rule->firstMonthLaari();
        $subsequent = $rule->subsequentMonthLaari();

        $tierLines = [[
            'label' => 'First overdue month: '.Money::fromLaari($first)->format(),
            'amount_laari' => $first,
        ]];

        if ($months > 1) {
            $tierLines[] = [
                'label' => sprintf(
                    '%d further month%s × %s',
                    $months - 1,
                    $months - 1 === 1 ? '' : 's',
                    Money::fromLaari($subsequent)->format(),
                ),
                'amount_laari' => ($months - 1) * $subsequent,
            ];
        }

        [$total, $capped] = $this->applyCap($rule, $first + ($months - 1) * $subsequent);

        return new FineBreakdown(
            method: FineMethod::TieredMonthly,
            baseLaari: $base->laari,
            dueDate: $due->toDateString(),
            allowanceDays: (int) $rule->allowance_days,
            asOf: $asOf->toDateString(),
            lateDays: $lateDays,
            overdueMonths: $months,
            dailyLaari: null,
            percentBps: null,
            tierLines: $tierLines,
            capLaari: $rule->cap_laari,
            capped: $capped,
            totalLaari: $total,
        );
    }

    /**
     * Overdue months commenced since the effective due date. Exactly n calendar
     * months late is still month n; a single day beyond commences month n + 1.
     */
    private function commencedOverdueMonths(CarbonImmutable $effectiveDue, CarbonImmutable $asOf): int
    {
        $wholeMonths = (int) $effectiveDue->diffInMonths($asOf, false);

        return $effectiveDue->addMonths($wholeMonths)->lessThan($asOf)
            ? $wholeMonths + 1
            : max($wholeMonths, 1);
    }

    /**
     * @return array{0: int, 1: bool} the (possibly capped) total and whether the cap bit
     */
    private function applyCap(FineRule $rule, int $totalLaari): array
    {
        if ($rule->cap_laari !== null && $totalLaari > $rule->cap_laari) {
            return [(int) $rule->cap_laari, true];
        }

        return [$totalLaari, false];
    }
}
