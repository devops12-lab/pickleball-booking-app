<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Court;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 day', '+30 days');
        $endsAt = (clone $startsAt)->modify('+1 hour');

        return [
            'tenant_id' => fn (array $attributes) => Court::find($attributes['court_id'])?->tenant_id,
            'court_id' => Court::factory(),
            'user_id' => User::factory(),
            'booking_reference' => 'CF-'.strtoupper(fake()->unique()->bothify('########')),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'total_amount' => fake()->randomFloat(2, 100, 2000),
            'status' => 'pending',
        ];
    }

    /**
     * Indicate the booking is confirmed.
     */
    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => 'confirmed']);
    }

    /**
     * Indicate the booking is completed.
     */
    public function completed(): static
    {
        return $this->state(fn () => ['status' => 'completed']);
    }

    /**
     * Indicate the booking is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }

    /**
     * Create a guest booking without a user account.
     */
    public function guest(): static
    {
        return $this->state(fn () => [
            'user_id' => null,
            'guest_name' => fake()->name(),
            'guest_phone' => '09'.fake()->numerify('#########'),
            'guest_email' => fake()->safeEmail(),
            'source' => 'guest',
        ]);
    }
}
