<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company().' Pickleball';

        return [
            'name' => $name,
            'slug' => str($name)->slug()->append('-'.fake()->unique()->numberBetween(1000, 9999)),
            'business_email' => fake()->unique()->companyEmail(),
            'business_phone' => fake()->phoneNumber(),
            'status' => 'active',
        ];
    }

    /**
     * Indicate the tenant is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }

    /**
     * Indicate the tenant is on trial.
     */
    public function trial(): static
    {
        return $this->state(fn () => ['status' => 'trial']);
    }
}
