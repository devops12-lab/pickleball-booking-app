<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\StaffInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StaffInvitation>
 */
class StaffInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role_id' => fn () => Role::where('name', 'staff')->first()?->id ?? Role::factory()->create(['name' => 'staff'])->id,
            'invited_by' => User::factory(),
            'token' => Str::random(64),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * Indicate the invitation has been accepted.
     */
    public function accepted(): static
    {
        return $this->state(fn () => ['status' => 'accepted']);
    }

    /**
     * Indicate the invitation has expired.
     */
    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
