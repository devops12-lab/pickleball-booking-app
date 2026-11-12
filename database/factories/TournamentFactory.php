<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tournament>
 */
class TournamentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->city().' '.fake()->randomElement(['Open', 'Classic', 'Invitational', 'Championship']);
        $startsAt = fake()->dateTimeBetween('+7 days', '+60 days');

        return [
            'tenant_id' => Tenant::factory(),
            'venue_id' => fn (array $attributes) => $attributes['tenant_id'],
            'organizer_id' => User::factory(),
            'name' => $name,
            'slug' => str($name)->slug()->append('-'.fake()->unique()->numberBetween(1000, 9999)),
            'description' => fake()->optional()->paragraph(),
            'format' => fake()->randomElement(['single_elimination', 'double_elimination', 'round_robin']),
            'match_type' => fake()->randomElement(['singles', 'doubles', 'mixed_doubles']),
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+2 days'),
            'registration_deadline' => (clone $startsAt)->modify('-3 days'),
            'entry_fee' => fake()->optional()->randomFloat(2, 100, 1000),
            'max_teams' => fake()->optional()->numberBetween(8, 64),
            'status' => 'upcoming',
        ];
    }

    /**
     * Indicate the tournament is in progress.
     */
    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => 'in_progress']);
    }

    /**
     * Indicate the tournament is completed.
     */
    public function completed(): static
    {
        return $this->state(fn () => ['status' => 'completed']);
    }

    /**
     * Indicate the tournament is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }
}
