<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['owner', 'staff', 'player', 'club_manager']),
            'label' => fn (array $attributes) => str($attributes['name'])->replace('_', ' ')->title()->toString(),
            'scope' => 'tenant',
        ];
    }
}
