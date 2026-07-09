<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CsrType;
use App\Enums\LeaseStatus;
use App\Enums\RentBasis;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lease>
 */
class LeaseFactory extends Factory
{
    protected $model = Lease::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-3 years', '-1 month');
        $duration = fake()->randomElement([10, 15, 25]);
        $expiry = (clone $start)->modify("+{$duration} years");

        return [
            'agreement_number' => fake()->unique()->numerify('AG-####/20##'),
            'property_id' => Property::factory(),
            'tenant_id' => Tenant::factory(),
            'agreement_date' => $start,
            'start_date' => $start,
            'rent_start_date' => $start,
            'duration_years' => $duration,
            'expiry_date' => $expiry,
            // Defaults to the PRD per-ft² example: 2,000 ft² × 53 laari = MVR 1,060.
            'rent_basis' => RentBasis::PerSquareFoot->value,
            'rate_laari' => 53,
            'area_sqft' => 2000,
            'flat_amount_laari' => null,
            // Draft by default so bulk creation never trips the one-active-lease
            // -per-parcel guard; use active() explicitly where needed.
            'status' => LeaseStatus::Draft->value,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => LeaseStatus::Active->value]);
    }

    public function flat(int $laari = 50000): static
    {
        return $this->state([
            'rent_basis' => RentBasis::Flat->value,
            'rate_laari' => null,
            'area_sqft' => null,
            'flat_amount_laari' => $laari,
        ]);
    }

    /**
     * An active lease whose expiry date is already in the past — for the
     * auto-expire behaviour (FR-LSE-03).
     */
    public function pastExpiry(): static
    {
        return $this->state([
            'status' => LeaseStatus::Active->value,
            'expiry_date' => now()->subDay()->toDateString(),
        ]);
    }

    public function graceMonths(int $months): static
    {
        return $this->state(['grace_months' => $months]);
    }

    public function dueDay(int $day): static
    {
        return $this->state(['due_day' => $day]);
    }

    public function csrFixed(int $annualLaari, int $month): static
    {
        return $this->state([
            'csr_type' => CsrType::FixedAnnual->value,
            'csr_amount_laari' => $annualLaari,
            'csr_month' => $month,
        ]);
    }

    public function csrPercent(int $bps, int $declaredRevenueLaari, int $month): static
    {
        return $this->state([
            'csr_type' => CsrType::PercentOfRevenue->value,
            'csr_percent_bps' => $bps,
            'csr_declared_revenue_laari' => $declaredRevenueLaari,
            'csr_month' => $month,
        ]);
    }
}
