<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TenantType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'type' => TenantType::Individual->value,
            'name' => fake()->name(),
            'national_id' => 'A'.fake()->unique()->numberBetween(100000, 999999),
            'company_reg_no' => null,
            'contact_person' => null,
            'mobile' => '+9607'.fake()->numberBetween(100000, 999999),
            'email' => fake()->optional()->safeEmail(),
            'postal_address' => fake()->optional()->address(),
        ];
    }

    public function organisation(): static
    {
        return $this->state(fn () => [
            'type' => TenantType::Organisation->value,
            'name' => fake()->company(),
            'national_id' => null,
            'company_reg_no' => 'C-'.fake()->unique()->numberBetween(100, 999).'/'.fake()->numberBetween(2000, 2020),
            'contact_person' => fake()->name(),
        ]);
    }
}
