<?php

namespace Database\Factories;

use App\Models\Court;
use App\Models\OpenPlaySession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpenPlaySession>
 */
class OpenPlaySessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 day', '+14 days');

        return [
            'tenant_id' => Tenant::factory(),
            'organizer_type' => 'user',
            'organizer_id' => User::factory(),
            'host_id' => fn (array $attributes) => $attributes['organizer_id'],
            'court_id' => Court::factory(),
            'title' => fake()->randomElement(['Friday Night Dinks', 'Open Play', 'Weekend Rally', 'Morning Mixer']),
            'session_type' => fake()->randomElement(['open', 'competitive', 'social']),
            'skill_level' => fake()->randomElement(['beginner', 'intermediate', 'advanced', 'all']),
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+2 hours'),
            'max_participants' => fake()->numberBetween(8, 32),
            'fee' => fake()->optional(0.7)->randomFloat(2, 50, 300),
            'status' => 'upcoming',
        ];
    }

    /**
     * Indicate the session is in progress.
     */
    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => 'in_progress']);
    }

    /**
     * Indicate the session is completed.
     */
    public function completed(): static
    {
        return $this->state(fn () => ['status' => 'completed']);
    }
}
