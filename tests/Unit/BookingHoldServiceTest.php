<?php

use App\Models\Booking;
use App\Models\BookingReceipt;
use App\Models\Court;
use App\Models\Facility;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BookingHoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'CourtFlex Test Facility',
        'slug' => 'test-facility',
        'business_email' => 'test@courtflex.test',
        'status' => 'active',
    ]);

    $this->facility = Facility::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'CourtFlex Arena',
        'slug' => 'courtflex-arena',
        'address' => '123 Pickleball Lane',
        'city' => 'Cebu',
        'is_active' => true,
    ]);

    $this->court = Court::create([
        'facility_id' => $this->facility->id,
        'tenant_id' => $this->tenant->id,
        'name' => 'Court 1',
        'hourly_rate' => 300.00,
        'is_active' => true,
    ]);

    $this->user = User::create([
        'name' => 'Player One',
        'email' => 'player1@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
});

function makeExpiredPendingHold(Tenant $tenant, Court $court, User $user, string $reference): Booking
{
    return Booking::create([
        'tenant_id' => $tenant->id,
        'court_id' => $court->id,
        'user_id' => $user->id,
        'booking_reference' => $reference,
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(2),
        'total_amount' => 300.00,
        'status' => 'pending',
        'payment_method' => 'gcash',
        'hold_expires_at' => now()->subMinutes(31),
    ]);
}

test('an unpaid hold past its expiry is cancelled with system history', function (): void {
    $booking = makeExpiredPendingHold($this->tenant, $this->court, $this->user, 'CF-EXPIRED');

    $cancelled = app(BookingHoldService::class)->cancelExpired();

    expect($cancelled)->toBe(1);
    expect($booking->fresh()->status)->toBe('cancelled');
    expect($booking->fresh()->hold_expires_at)->toBeNull();
    $this->assertDatabaseHas('booking_status_histories', [
        'booking_id' => $booking->id,
        'changed_by' => null,
        'from_status' => 'pending',
        'to_status' => 'cancelled',
    ]);
});

test('a receipt waiting for review keeps the hold open', function (): void {
    $booking = makeExpiredPendingHold($this->tenant, $this->court, $this->user, 'CF-IN-REVIEW');
    BookingReceipt::create([
        'booking_id' => $booking->id,
        'image_url' => 'receipts/proof.png',
        'reference_number' => 'GCASH123456',
        'status' => 'in_review',
    ]);

    $cancelled = app(BookingHoldService::class)->cancelExpired();

    expect($cancelled)->toBe(0);
    expect($booking->fresh()->status)->toBe('pending');
    expect($booking->fresh()->hold_expires_at)->not->toBeNull();
});
