<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Club>
 */
class ClubFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company().' Pickleball Club';

        return [
            'tenant_id' => Tenant::factory(),
            'creator_id' => User::factory(),
            'name' => $name,
            'slug' => str($name)->slug()->append('-'.fake()->unique()->numberBetween(1000, 9999)),
            'description' => fake()->optional()->paragraph(),
            'privacy' => fake()->randomElement(['public', 'private']),
            'max_members' => fake()->optional()->numberBetween(20, 200),
            'status' => 'active',
        ];
    }

    /**
     * Indicate the club is private.
     */
    public function privateClub(): static
    {
        return $this->state(fn () => ['privacy' => 'private']);
    }
}
