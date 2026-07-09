<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FineBase;
use App\Enums\FineMethod;
use App\Models\FineRule;
use App\Models\Lease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FineRule>
 */
class FineRuleFactory extends Factory
{
    protected $model = FineRule::class;

    public function definition(): array
    {
        // Tiered with the locked defaults: MVR 100 first month, MVR 50 after.
        return [
            'lease_id' => Lease::factory(),
            'method' => FineMethod::TieredMonthly->value,
            'base' => FineBase::Rent->value,
            'allowance_days' => 0,
            'first_month_laari' => FineRule::DEFAULT_FIRST_MONTH_LAARI,
            'subsequent_month_laari' => FineRule::DEFAULT_SUBSEQUENT_MONTH_LAARI,
            'effective_from' => '2020-01-01',
        ];
    }

    public function flatPerDay(int $dailyLaari): static
    {
        return $this->state([
            'method' => FineMethod::FlatPerDay->value,
            'flat_daily_laari' => $dailyLaari,
        ]);
    }

    public function percentPerDay(int $bps): static
    {
        return $this->state([
            'method' => FineMethod::PercentPerDay->value,
            'percent_daily_bps' => $bps,
        ]);
    }

    public function tiered(int $firstLaari, int $subsequentLaari): static
    {
        return $this->state([
            'method' => FineMethod::TieredMonthly->value,
            'first_month_laari' => $firstLaari,
            'subsequent_month_laari' => $subsequentLaari,
        ]);
    }

    public function baseRentPlusCharges(): static
    {
        return $this->state(['base' => FineBase::RentPlusCharges->value]);
    }

    public function allowanceDays(int $days): static
    {
        return $this->state(['allowance_days' => $days]);
    }

    public function cap(int $laari): static
    {
        return $this->state(['cap_laari' => $laari]);
    }
}
