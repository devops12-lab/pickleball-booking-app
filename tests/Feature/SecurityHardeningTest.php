<?php

use App\Models\Booking;
use App\Models\Court;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\TokenManagementService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

// ── Token revocation on password change ──────────────────────────

test('password change revokes other device tokens', function (): void {
    $user = User::factory()->create(['password' => Hash::make('OldPassword123!')]);

    // Simulate three active sessions
    $user->createToken('device_1');
    $user->createToken('device_2');
    $keepToken = $user->createToken('current_device');

    expect($user->tokens()->count())->toBe(3);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/players/auth/change-password', [
            'current_password' => 'OldPassword123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Password changed successfully.');

    // actingAs uses TransientToken (no id), so all tokens are deleted
    expect($user->tokens()->count())->toBe(0);
});

// ── Role-scoped token abilities ──────────────────────────────────

test('createRoleToken assigns correct abilities per role', function (): void {
    $service = app(TokenManagementService::class);
    $user = User::factory()->create();

    $service->createRoleToken($user, 'player');
    $playerToken = $user->tokens()->latest('id')->first();

    expect($playerToken->abilities)->toBe(['player:*', 'bookings:create', 'bookings:read', 'clubs:join']);

    $service->createRoleToken($user, 'staff');
    $staffToken = $user->tokens()->latest('id')->first();

    expect($staffToken->abilities)->toBe(['staff:*', 'bookings:*', 'payments:verify', 'courts:read']);

    $service->createRoleToken($user, 'system_admin');
    $adminToken = $user->tokens()->latest('id')->first();

    expect($adminToken->abilities)->toBe(['*']);
});

test('createRoleToken falls back to wildcard for unknown role', function (): void {
    $service = app(TokenManagementService::class);
    $user = User::factory()->create();

    $service->createRoleToken($user, 'unknown_role');
    $token = $user->tokens()->latest('id')->first();

    expect($token->abilities)->toBe(['*']);
});

// ── Password reset token expiration ──────────────────────────────

test('expired password reset token is rejected', function (): void {
    $service = app(PasswordResetService::class);
    $user = User::factory()->create(['email' => 'expire@courtflex.test']);

    // Insert a token created 61 minutes ago (past the 60-minute window)
    $rawToken = 'test-token-value-for-expiration';
    DB::table('password_reset_tokens')->insert([
        'email' => 'expire@courtflex.test',
        'token' => Hash::make($rawToken),
        'created_at' => now()->subMinutes(61),
    ]);

    $result = $service->resetPassword('expire@courtflex.test', $rawToken, 'NewPassword123!');

    expect($result)->toBeFalse();
    expect(Hash::check('NewPassword123!', $user->fresh()->password))->toBeFalse();
});

test('valid password reset token within expiration window succeeds', function (): void {
    $service = app(PasswordResetService::class);
    $user = User::factory()->create(['email' => 'valid@courtflex.test']);

    $rawToken = 'test-token-value-valid';
    DB::table('password_reset_tokens')->insert([
        'email' => 'valid@courtflex.test',
        'token' => Hash::make($rawToken),
        'created_at' => now()->subMinutes(30),
    ]);

    $result = $service->resetPassword('valid@courtflex.test', $rawToken, 'NewPassword123!');

    expect($result)->toBeTrue();
    expect(Hash::check('NewPassword123!', $user->fresh()->password))->toBeTrue();
});

// ── Double-booking conflict response ─────────────────────────────

test('player booking returns conflict when slot is taken', function (): void {
    $court = Court::factory()->create(['hourly_rate' => 500]);
    $player = User::factory()->create();

    $startsAt = now()->addDay()->startOfHour()->toDateTimeString();
    $endsAt = now()->addDay()->startOfHour()->addHour()->toDateTimeString();

    // Create an existing confirmed booking in that slot
    Booking::factory()->confirmed()->create([
        'court_id' => $court->id,
        'tenant_id' => $court->tenant_id,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
    ]);

    $response = $this->actingAs($player)
        ->postJson('/api/v1/players/bookings', [
            'court_id' => $court->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Court is unavailable or conflicts with another schedule.');
});

// ── Payment adjustment validation ────────────────────────────────

test('payment adjustment rejects zero amount', function (): void {
    $tenant = Tenant::factory()->create();
    $staff = User::factory()->create();
    TenantUser::factory()->owner()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $staff->id,
    ]);

    $booking = Booking::factory()->confirmed()->create(['tenant_id' => $tenant->id]);
    $payment = Payment::factory()->verified()->create([
        'booking_id' => $booking->id,
        'tenant_id' => $tenant->id,
        'amount' => '500.00',
    ]);

    $response = $this->actingAs($staff)
        ->postJson("/api/v1/clubs/{$tenant->id}/bookings/{$booking->id}/payments/{$payment->id}/adjustments", [
            'amount' => '0.00',
            'adjustment_type' => 'refund',
            'reason' => 'Test zero refund',
        ]);

    $response->assertStatus(422);
});

test('payment adjustment rejects amount exceeding remaining balance', function (): void {
    $tenant = Tenant::factory()->create();
    $staff = User::factory()->create();
    TenantUser::factory()->owner()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $staff->id,
    ]);

    $booking = Booking::factory()->confirmed()->create(['tenant_id' => $tenant->id]);
    $payment = Payment::factory()->verified()->create([
        'booking_id' => $booking->id,
        'tenant_id' => $tenant->id,
        'amount' => '500.00',
    ]);

    $response = $this->actingAs($staff)
        ->postJson("/api/v1/clubs/{$tenant->id}/bookings/{$booking->id}/payments/{$payment->id}/adjustments", [
            'amount' => '600.00',
            'adjustment_type' => 'refund',
            'reason' => 'Over-refund test',
        ]);

    $response->assertStatus(422);
});

// ── Walk-in booking conflict ─────────────────────────────────────

test('walk-in booking returns conflict when slot is taken', function (): void {
    $tenant = Tenant::factory()->create();
    $staff = User::factory()->create();
    $player = User::factory()->create();
    TenantUser::factory()->owner()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $staff->id,
    ]);

    $court = Court::factory()->create([
        'tenant_id' => $tenant->id,
        'hourly_rate' => 500,
    ]);

    $startsAt = now()->addDay()->startOfHour()->toIso8601String();
    $endsAt = now()->addDay()->startOfHour()->addHour()->toIso8601String();

    // Block the slot
    Booking::factory()->confirmed()->create([
        'court_id' => $court->id,
        'tenant_id' => $tenant->id,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
    ]);

    $response = $this->actingAs($staff)
        ->postJson("/api/v1/clubs/{$tenant->id}/bookings/walk-in", [
            'court_id' => $court->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'player_id' => $player->id,
        ]);

    $response->assertStatus(409);
});
