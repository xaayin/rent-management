<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\FineMethod;
use App\Support\Money;

/**
 * The fully itemised result of a fine calculation (FR-FIN-06/12): the method,
 * the due/set date, late days or overdue months, per-tier amounts and the
 * total — so any figure on an invoice can be independently verified.
 */
final class FineBreakdown
{
    /**
     * @param  list<array{label: string, amount_laari: int}>  $tierLines
     */
    public function __construct(
        public readonly FineMethod $method,
        public readonly int $baseLaari,
        public readonly string $dueDate,
        public readonly int $allowanceDays,
        public readonly string $asOf,
        public readonly int $lateDays,
        public readonly ?int $overdueMonths,
        public readonly ?int $dailyLaari,
        public readonly ?int $percentBps,
        public readonly array $tierLines,
        public readonly ?int $capLaari,
        public readonly bool $capped,
        public readonly int $totalLaari,
    ) {}

    public static function none(FineMethod $method, int $baseLaari, string $dueDate, int $allowanceDays, string $asOf): self
    {
        return new self(
            method: $method,
            baseLaari: $baseLaari,
            dueDate: $dueDate,
            allowanceDays: $allowanceDays,
            asOf: $asOf,
            lateDays: 0,
            overdueMonths: null,
            dailyLaari: null,
            percentBps: null,
            tierLines: [],
            capLaari: null,
            capped: false,
            totalLaari: 0,
        );
    }

    public function total(): Money
    {
        return Money::fromLaari($this->totalLaari);
    }

    /**
     * One-line plain-English description for the invoice line item.
     */
    public function summary(): string
    {
        if ($this->totalLaari === 0) {
            return 'Late fine — none';
        }

        $line = match ($this->method) {
            FineMethod::FlatPerDay => sprintf(
                'Late fine — %d day(s) late × %s/day',
                $this->lateDays,
                Money::fromLaari((int) $this->dailyLaari)->format(),
            ),
            FineMethod::PercentPerDay => sprintf(
                'Late fine — %d day(s) late × %s/day (%s%%/day of %s)',
                $this->lateDays,
                Money::fromLaari((int) $this->dailyLaari)->format(),
                rtrim(rtrim(number_format((int) $this->percentBps / 100, 2, '.', ''), '0'), '.'),
                Money::fromLaari($this->baseLaari)->format(),
            ),
            FineMethod::TieredMonthly => sprintf(
                'Late fine — %d overdue month%s (%s)',
                (int) $this->overdueMonths,
                $this->overdueMonths === 1 ? '' : 's',
                implode(' + ', array_map(
                    fn (array $tier): string => $tier['label'],
                    $this->tierLines,
                )),
            ),
        };

        return $this->capped
            ? $line.sprintf(', capped at %s', Money::fromLaari((int) $this->capLaari)->format())
            : $line;
    }

    /**
     * Structured breakdown persisted as the fine line item's meta (FR-FIN-12).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method->value,
            'base_laari' => $this->baseLaari,
            'due_date' => $this->dueDate,
            'allowance_days' => $this->allowanceDays,
            'as_of' => $this->asOf,
            'late_days' => $this->lateDays,
            'overdue_months' => $this->overdueMonths,
            'daily_laari' => $this->dailyLaari,
            'percent_bps' => $this->percentBps,
            'tier_lines' => $this->tierLines,
            'cap_laari' => $this->capLaari,
            'capped' => $this->capped,
            'total_laari' => $this->totalLaari,
        ];
    }
}
