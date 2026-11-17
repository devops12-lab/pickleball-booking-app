<?php

use App\Models\Booking;
use App\Models\Court;
use App\Models\Facility;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ownerRole = Role::where('name', 'owner')->first();
    $this->staffRole = Role::where('name', 'staff')->first();

    $this->tenant = Tenant::create([
        'name' => 'Guest Booking Venue',
        'slug' => 'guest-booking-venue',
        'business_email' => 'venue@courtflex.test',
        'status' => 'active',
    ]);

    $this->owner = User::create([
        'name' => 'Venue Owner',
        'email' => 'owner@guest.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->owner->id,
        'role_id' => $this->ownerRole->id,
        'status' => 'active',
    ]);

    $this->staff = User::create([
        'name' => 'Venue Staff',
        'email' => 'staff@guest.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->staff->id,
        'role_id' => $this->staffRole->id,
        'status' => 'active',
    ]);

    $this->facility = Facility::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Facility',
        'slug' => 'test-facility',
        'address' => '123 Test Rd',
        'city' => 'Manila',
        'is_active' => true,
    ]);

    $this->court = Court::create([
        'facility_id' => $this->facility->id,
        'tenant_id' => $this->tenant->id,
        'name' => 'Court 1',
        'hourly_rate' => 300.00,
        'is_active' => true,
    ]);
});

// ──────────────────────────────────────────────────────────────
// Owner Guest Bookings
// ──────────────────────────────────────────────────────────────

test('owner can create a walk-in guest booking', function (): void {
    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->postJson('/api/v1/owners/guest-bookings', [
        'court_id' => $this->court->id,
        'guest_name' => 'Walk-in Guest',
        'guest_email' => 'walkin@guest.test',
        'guest_phone' => '09171234567',
        'starts_at' => now()->addDay()->setHour(10)->setMinute(0)->toDateTimeString(),
        'ends_at' => now()->addDay()->setHour(11)->setMinute(0)->toDateTimeString(),
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.guest_name', 'Walk-in Guest');

    $this->assertDatabaseHas('bookings', [
        'guest_email' => 'walkin@guest.test',
        'source' => 'walk_in',
        'status' => 'confirmed',
    ]);
});

test('owner can list guest bookings for their venue', function (): void {
    Booking::create([
        'tenant_id' => $this->tenant->id,
        'court_id' => $this->court->id,
        'user_id' => null,
        'guest_name' => 'Test Guest',
        'guest_email' => 'test@guest.test',
        'guest_phone' => '09171234567',
        'booking_reference' => 'ABC12345',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'total_amount' => 300,
        'status' => 'confirmed',
        'source' => 'walk_in',
        'payment_method' => 'cash',
    ]);

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->getJson('/api/v1/owners/guest-bookings');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data');
});

test('owner can cancel a guest booking', function (): void {
    $booking = Booking::create([
        'tenant_id' => $this->tenant->id,
        'court_id' => $this->court->id,
        'user_id' => null,
        'guest_name' => 'Cancel Guest',
        'guest_email' => 'cancel@guest.test',
        'guest_phone' => '09171234567',
        'booking_reference' => 'CANCEL01',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'total_amount' => 300,
        'status' => 'confirmed',
        'source' => 'walk_in',
        'payment_method' => 'cash',
    ]);

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->postJson("/api/v1/owners/guest-bookings/{$booking->id}/cancel");

    $response->assertOk()
        ->assertJsonPath('success', true);

    expect($booking->fresh()->status)->toBe('cancelled');
});

// ──────────────────────────────────────────────────────────────
// Staff Guest Bookings
// ──────────────────────────────────────────────────────────────

test('staff can create a walk-in guest booking', function (): void {
    $token = $this->staff->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->postJson('/api/v1/staff/guest-bookings', [
        'court_id' => $this->court->id,
        'guest_name' => 'Staff Walk-in',
        'guest_email' => 'staffwalkin@guest.test',
        'guest_phone' => '09171234567',
        'starts_at' => now()->addDay()->setHour(14)->setMinute(0)->toDateTimeString(),
        'ends_at' => now()->addDay()->setHour(15)->setMinute(0)->toDateTimeString(),
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true);
});

// ──────────────────────────────────────────────────────────────
// Public Guest Bookings
// ──────────────────────────────────────────────────────────────

test('public guest can request a verification code', function (): void {
    $response = $this->postJson('/api/v1/public/guest-bookings/code', [
        'name' => 'Public Guest',
        'email' => 'publicguest@test.com',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['verification_id', 'email', 'expires_at']]);

    $this->assertDatabaseHas('guest_booking_codes', [
        'email' => 'publicguest@test.com',
    ]);
});

test('public guest cannot request code with invalid email', function (): void {
    $response = $this->postJson('/api/v1/public/guest-bookings/code', [
        'name' => 'Public Guest',
        'email' => 'not-an-email',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('public guest can find booking by reference and email', function (): void {
    Booking::create([
        'tenant_id' => $this->tenant->id,
        'court_id' => $this->court->id,
        'user_id' => null,
        'guest_name' => 'Findable Guest',
        'guest_email' => 'findme@guest.test',
        'guest_phone' => '09171234567',
        'booking_reference' => 'FIND1234',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'total_amount' => 300,
        'status' => 'confirmed',
        'source' => 'guest_web',
        'payment_method' => 'pay_at_court',
    ]);

    $response = $this->postJson('/api/v1/public/guest-bookings/find', [
        'booking_code' => 'FIND1234',
        'email' => 'findme@guest.test',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true);
});

test('public guest lookup fails with wrong email', function (): void {
    Booking::create([
        'tenant_id' => $this->tenant->id,
        'court_id' => $this->court->id,
        'user_id' => null,
        'guest_name' => 'Secure Guest',
        'guest_email' => 'real@guest.test',
        'guest_phone' => '09171234567',
        'booking_reference' => 'SECURE01',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'total_amount' => 300,
        'status' => 'confirmed',
        'source' => 'guest_web',
        'payment_method' => 'pay_at_court',
    ]);

    $response = $this->postJson('/api/v1/public/guest-bookings/find', [
        'booking_code' => 'SECURE01',
        'email' => 'wrong@guest.test',
    ]);

    $response->assertStatus(404);
});

test('non-owner cannot access owner guest booking endpoints', function (): void {
    $player = User::create([
        'name' => 'Random Player',
        'email' => 'player@guest.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $token = $player->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->getJson('/api/v1/owners/guest-bookings');

    $response->assertStatus(403);
});
