<?php

namespace Database\Factories;

use App\Models\Court;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Court>
 */
class CourtFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => Facility::factory(),
            'tenant_id' => fn (array $attributes) => Facility::find($attributes['facility_id'])?->tenant_id,
            'name' => 'Court '.fake()->unique()->numberBetween(1, 999),
            'court_code' => strtoupper(fake()->unique()->bothify('CT-##??')),
            'court_type' => fake()->randomElement(['indoor', 'outdoor', 'covered']),
            'surface_type' => fake()->randomElement(['concrete', 'wood', 'synthetic', 'asphalt']),
            'hourly_rate' => fake()->randomFloat(2, 100, 1000),
            'is_active' => true,
        ];
    }

    /**
     * Indicate the court is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
