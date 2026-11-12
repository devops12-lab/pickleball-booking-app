<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantUser>
 */
class TenantUserFactory extends Factory
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
            'user_id' => User::factory(),
            'role_id' => fn () => Role::where('name', 'player')->first()?->id ?? Role::factory()->create(['name' => 'player'])->id,
            'status' => 'active',
        ];
    }

    /**
     * Assign the owner role.
     */
    public function owner(): static
    {
        return $this->state(fn () => [
            'role_id' => fn () => Role::where('name', 'owner')->first()?->id ?? Role::factory()->create(['name' => 'owner'])->id,
        ]);
    }

    /**
     * Assign the staff role.
     */
    public function staff(): static
    {
        return $this->state(fn () => [
            'role_id' => fn () => Role::where('name', 'staff')->first()?->id ?? Role::factory()->create(['name' => 'staff'])->id,
        ]);
    }
}
