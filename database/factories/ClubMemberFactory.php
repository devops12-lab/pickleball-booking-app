<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\ClubMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClubMember>
 */
class ClubMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'club_id' => Club::factory(),
            'user_id' => User::factory(),
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ];
    }

    /**
     * Indicate the member is a manager.
     */
    public function manager(): static
    {
        return $this->state(fn () => ['role' => 'manager']);
    }

    /**
     * Indicate the member is an organizer.
     */
    public function organizer(): static
    {
        return $this->state(fn () => ['role' => 'organizer']);
    }

    /**
     * Indicate the member is pending approval.
     */
    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'joined_at' => null,
            'requested_at' => now(),
        ]);
    }
}
