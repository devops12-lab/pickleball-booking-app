<?php

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtBlockedSchedule;
use App\Models\CourtOperatingHour;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BookingAvailabilityService;
use App\Services\PaymentVerificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->availabilityService = app(BookingAvailabilityService::class);
    $this->paymentVerificationService = app(PaymentVerificationService::class);

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
        'name' => 'Championship Court 1',
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

test('court is available when no reservations exist', function (): void {
    // Next Monday at 10:00 to 11:00
    $start = Carbon::now()->next(Carbon::MONDAY)->setTime(10, 0);
    $end = (clone $start)->addHour();

    $available = $this->availabilityService->isAvailable($this->court, $start, $end);

    expect($available)->toBeTrue();
});

test('court is unavailable when overlapping booking exists', function (): void {
    $start = Carbon::now()->next(Carbon::TUESDAY)->setTime(14, 0);
    $end = (clone $start)->addHours(2); // 14:00 - 16:00

    Booking::create([
        'tenant_id' => $this->tenant->id,
        'court_id' => $this->court->id,
        'user_id' => $this->user->id,
        'booking_reference' => 'CF-EXISTING',
        'starts_at' => $start,
        'ends_at' => $end,
        'total_amount' => 600.00,
        'status' => 'confirmed',
    ]);

    // Overlapping query: 15:00 - 17:00
    $queryStart = (clone $start)->addHour();
    $queryEnd = (clone $queryStart)->addHours(2);

    $available = $this->availabilityService->isAvailable($this->court, $queryStart, $queryEnd);

    expect($available)->toBeFalse();
});

test('court is unavailable during blocked maintenance schedules', function (): void {
    $start = Carbon::now()->next(Carbon::WEDNESDAY)->setTime(8, 0);
    $end = (clone $start)->addHours(4); // 8:00 - 12:00

    CourtBlockedSchedule::create([
        'court_id' => $this->court->id,
        'tenant_id' => $this->tenant->id,
        'starts_at' => $start,
        'ends_at' => $end,
        'reason' => 'Resurfacing maintenance',
        'created_by' => $this->user->id,
    ]);

    // Attempt booking 9:00 - 10:00
    $bookingStart = (clone $start)->addHour();
    $bookingEnd = (clone $bookingStart)->addHour();

    $available = $this->availabilityService->isAvailable($this->court, $bookingStart, $bookingEnd);

    expect($available)->toBeFalse();
});

test('court is unavailable outside configured operating hours', function (): void {
    $nextThursday = Carbon::now()->next(Carbon::THURSDAY);
    $dayOfWeek = $nextThursday->dayOfWeek;

    // Operating hours: 09:00 - 18:00
    CourtOperatingHour::create([
        'court_id' => $this->court->id,
        'day_of_week' => $dayOfWeek,
        'open_time' => '09:00:00',
        'close_time' => '18:00:00',
        'is_closed' => false,
    ]);

    // Attempt booking at 19:00 - 20:00 (after close)
    $eveningStart = (clone $nextThursday)->setTime(19, 0);
    $eveningEnd = (clone $eveningStart)->addHour();

    $available = $this->availabilityService->isAvailable($this->court, $eveningStart, $eveningEnd);

    expect($available)->toBeFalse();
});

test('payment approval transitions booking status and logs audit', function (): void {
    $verifier = User::create([
        'name' => 'Verifier Staff',
        'email' => 'staff@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $booking = Booking::create([
        'tenant_id' => $this->tenant->id,
        'court_id' => $this->court->id,
        'user_id' => $this->user->id,
        'booking_reference' => 'CF-PAYTEST',
        'starts_at' => Carbon::now()->addDays(2),
        'ends_at' => Carbon::now()->addDays(2)->addHour(),
        'total_amount' => 300.00,
        'status' => 'pending',
    ]);

    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $booking->id,
        'user_id' => $this->user->id,
        'amount' => 300.00,
        'currency' => 'PHP',
        'payment_method' => 'gcash',
        'transaction_reference' => 'GCASH-TX-9988',
        'status' => 'pending',
    ]);

    $verification = $this->paymentVerificationService->approve(
        $payment,
        $verifier,
        'Payment verified via GCash reference'
    );

    expect($verification->status)->toBe('approved');
    expect($payment->fresh()->status)->toBe('verified');
    expect($booking->fresh()->status)->toBe('confirmed');

    $this->assertDatabaseHas('audit_logs', [
        'tenant_id' => $this->tenant->id,
        'action' => 'payment.verified',
    ]);
});
