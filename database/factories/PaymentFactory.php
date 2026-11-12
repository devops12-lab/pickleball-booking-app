<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes) => Booking::find($attributes['booking_id'])?->tenant_id,
            'booking_id' => Booking::factory(),
            'user_id' => User::factory(),
            'amount' => fake()->randomFloat(2, 100, 2000),
            'currency' => 'PHP',
            'payment_method' => fake()->randomElement(['gcash', 'maya', 'cash', 'bank_transfer']),
            'transaction_reference' => strtoupper(fake()->unique()->bothify('TX-############')),
            'status' => 'pending',
        ];
    }

    /**
     * Indicate the payment is verified.
     */
    public function verified(): static
    {
        return $this->state(fn () => ['status' => 'verified']);
    }

    /**
     * Indicate the payment is rejected.
     */
    public function rejected(): static
    {
        return $this->state(fn () => ['status' => 'rejected']);
    }
}
