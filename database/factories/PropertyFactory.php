<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PropertyStatus;
use App\Enums\UsageType;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    protected $model = Property::class;

    public function definition(): array
    {
        return [
            'name' => fake()->streetName().' Plot',
            'land_number' => 'L-'.fake()->unique()->numberBetween(10000, 99999),
            'size_sqft' => fake()->numberBetween(500, 5000),
            'usage_type' => fake()->randomElement(UsageType::cases())->value,
            'location_notes' => fake()->optional()->sentence(),
            'status' => PropertyStatus::Active->value,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => PropertyStatus::Archived->value]);
    }
}
