<?php

use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
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
        'name' => 'Staff Test Venue',
        'slug' => 'staff-test-venue',
        'business_email' => 'staff@courtflex.test',
        'status' => 'active',
    ]);

    $this->owner = User::create([
        'name' => 'Venue Owner',
        'email' => 'owner@stafftest.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->owner->id,
        'role_id' => $this->ownerRole->id,
        'status' => 'active',
    ]);

    // Another tenant for isolation tests
    $this->otherTenant = Tenant::create([
        'name' => 'Other Venue',
        'slug' => 'other-venue',
        'business_email' => 'other@courtflex.test',
        'status' => 'active',
    ]);
    $this->otherOwner = User::create([
        'name' => 'Other Owner',
        'email' => 'otherowner@stafftest.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->otherTenant->id,
        'user_id' => $this->otherOwner->id,
        'role_id' => $this->ownerRole->id,
        'status' => 'active',
    ]);

    // Subscription plan and active subscription for the main tenant
    $this->plan = SubscriptionPlan::create([
        'name' => 'Test Plan',
        'slug' => 'test-plan',
        'price' => 1499.00,
        'billing_interval' => 'monthly',
        'max_courts' => 4,
        'max_staff' => 3,
        'features' => ['court_booking'],
        'is_active' => true,
    ]);
    TenantSubscription::create([
        'tenant_id' => $this->tenant->id,
        'subscription_plan_id' => $this->plan->id,
        'status' => 'active',
        'starts_at' => now()->subMonth(),
    ]);

    $this->ownerToken = $this->owner->createToken('test')->plainTextToken;
    $this->ownerHeaders = ['Authorization' => "Bearer {$this->ownerToken}", 'X-Tenant-ID' => $this->tenant->id];
});

// ──────────────────────────────────────────────────────────────
// List Staff
// ──────────────────────────────────────────────────────────────

test('owner can list their staff', function (): void {
    $staff = User::create([
        'name' => 'Staff One',
        'email' => 'staff1@test.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $staff->id,
        'role_id' => $this->staffRole->id,
        'status' => 'active',
    ]);

    $response = $this->withHeaders($this->ownerHeaders)
        ->getJson('/api/v1/owners/staff');

    $response->assertOk();
    // Owner + 1 staff
    expect(count($response->json('data')))->toBe(2);
});

// ──────────────────────────────────────────────────────────────
// Create Staff
// ──────────────────────────────────────────────────────────────

test('owner can create a new staff member', function (): void {
    $response = $this->withHeaders($this->ownerHeaders)
        ->postJson('/api/v1/owners/staff', [
            'name' => 'New Staff',
            'email' => 'newstaff@test.test',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.user.email', 'newstaff@test.test')
        ->assertJsonPath('data.role', 'staff');

    $this->assertDatabaseHas('users', ['email' => 'newstaff@test.test']);
    $this->assertDatabaseHas('tenant_users', [
        'tenant_id' => $this->tenant->id,
        'status' => 'invited',
    ]);
});

test('owner can add an existing user as staff', function (): void {
    $existingUser = User::create([
        'name' => 'Existing Player',
        'email' => 'existing@test.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $response = $this->withHeaders($this->ownerHeaders)
        ->postJson('/api/v1/owners/staff', [
            'name' => 'Existing Player',
            'email' => 'existing@test.test',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'active');
});

test('cannot add duplicate active staff', function (): void {
    $staff = User::create([
        'name' => 'Staff',
        'email' => 'dup@test.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $staff->id,
        'role_id' => $this->staffRole->id,
        'status' => 'active',
    ]);

    $response = $this->withHeaders($this->ownerHeaders)
        ->postJson('/api/v1/owners/staff', [
            'name' => 'Staff Member',
            'email' => 'dup@test.test',
        ]);

    $response->assertStatus(422);
});

// ──────────────────────────────────────────────────────────────
// Role Restriction
// ──────────────────────────────────────────────────────────────

test('cannot assign owner role through staff creation', function (): void {
    $response = $this->withHeaders($this->ownerHeaders)
        ->postJson('/api/v1/owners/staff', [
            'name' => 'Hacker Person',
            'email' => 'hacker@test.test',
            'role' => 'owner',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);
});

test('cannot assign system_admin role through staff creation', function (): void {
    $response = $this->withHeaders($this->ownerHeaders)
        ->postJson('/api/v1/owners/staff', [
            'name' => 'Hacker Person',
            'email' => 'hacker@test.test',
            'role' => 'system_admin',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);
});

// ──────────────────────────────────────────────────────────────
// Show Staff
// ──────────────────────────────────────────────────────────────

test('owner can view a staff member', function (): void {
    $staff = User::create([
        'name' => 'View Staff',
        'email' => 'view@test.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $staff->id,
        'role_id' => $this->staffRole->id,
        'status' => 'active',
    ]);

    $response = $this->withHeaders($this->ownerHeaders)
        ->getJson("/api/v1/owners/staff/{$staff->id}");

    $response->assertOk()
        ->assertJsonPath('data.user.name', 'View Staff');
});

// ──────────────────────────────────────────────────────────────
// Update Staff
// ──────────────────────────────────────────────────────────────

test('owner can update staff info', function (): void {
    $staff = User::create([
        'name' => 'Old Name',
        'email' => 'update@test.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $staff->id,
        'role_id' => $this->staffRole->id,
        'status' => 'active',
    ]);

    $response = $this->withHeaders($this->ownerHeaders)
        ->putJson("/api/v1/owners/staff/{$staff->id}", [
            'name' => 'New Name',
            'phone' => '09171234567',
        ]);

    $response->assertOk();
    expect($staff->fresh()->name)->toBe('New Name');
    expect($staff->fresh()->phone)->toBe('09171234567');
});

// ──────────────────────────────────────────────────────────────
// Status Toggle
// ──────────────────────────────────────────────────────────────

test('owner can deactivate a staff member', function (): void {
    $staff = User::create([
        'name' => 'Deactivate Me',
        'email' => 'deactivate@test.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $staff->id,
        'role_id' => $this->staffRole->id,
        'status' => 'active',
    ]);

    $response = $this->withHeaders($this->ownerHeaders)
        ->patchJson("/api/v1/owners/staff/{$staff->id}/status", [
            'status' => 'inactive',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'inactive');
});

test('owner cannot deactivate themselves', function (): void {
    $response = $this->withHeaders($this->ownerHeaders)
        ->patchJson("/api/v1/owners/staff/{$this->owner->id}/status", [
            'status' => 'inactive',
        ]);

    $response->assertUnprocessable();
});

// ──────────────────────────────────────────────────────────────
// Credentials
// ──────────────────────────────────────────────────────────────

test('owner can update staff credentials', function (): void {
    $staff = User::create([
        'name' => 'Cred Staff',
        'email' => 'oldcred@test.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $staff->id,
        'role_id' => $this->staffRole->id,
        'status' => 'active',
    ]);

    // Create a token for the staff member
    $staff->createToken('staff-session');
    expect($staff->tokens()->count())->toBe(1);

    $response = $this->withHeaders($this->ownerHeaders)
        ->putJson("/api/v1/owners/staff/{$staff->id}/credentials", [
            'email' => 'newcred@test.test',
            'password' => 'NewSecure123!',
            'password_confirmation' => 'NewSecure123!',
        ]);

    $response->assertOk();
    expect($staff->fresh()->email)->toBe('newcred@test.test');
    expect($staff->tokens()->count())->toBe(0);
});

test('password hashes are never exposed in responses', function (): void {
    $staff = User::create([
        'name' => 'Hash Staff',
        'email' => 'hash@test.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $staff->id,
        'role_id' => $this->staffRole->id,
        'status' => 'active',
    ]);

    $response = $this->withHeaders($this->ownerHeaders)
        ->getJson("/api/v1/owners/staff/{$staff->id}");

    $response->assertOk();
    $json = $response->json();
    $jsonString = json_encode($json);
    expect($jsonString)->not->toContain('password');
});

// ──────────────────────────────────────────────────────────────
// Tenant Isolation
// ──────────────────────────────────────────────────────────────

test('owner cannot access another tenants staff', function (): void {
    $otherStaff = User::create([
        'name' => 'Other Staff',
        'email' => 'othertenant@test.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->otherTenant->id,
        'user_id' => $otherStaff->id,
        'role_id' => $this->staffRole->id,
        'status' => 'active',
    ]);

    $response = $this->withHeaders($this->ownerHeaders)
        ->getJson("/api/v1/owners/staff/{$otherStaff->id}");

    $response->assertNotFound();
});

// ──────────────────────────────────────────────────────────────
// Subscription Limits
// ──────────────────────────────────────────────────────────────

test('staff creation blocked when subscription limit reached', function (): void {
    // Plan allows 3 staff (owner counts as 1)
    for ($i = 1; $i <= 2; $i++) {
        $s = User::create([
            'name' => "Staff {$i}",
            'email' => "limit{$i}@test.test",
            'password' => Hash::make('Password123!'),
            'account_status' => 'active',
        ]);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $s->id,
            'role_id' => $this->staffRole->id,
            'status' => 'active',
        ]);
    }

    // 3rd staff (owner + 2 existing = 3, at limit)
    $response = $this->withHeaders($this->ownerHeaders)
        ->postJson('/api/v1/owners/staff', [
            'name' => 'Over Limit',
            'email' => 'overlimit@test.test',
        ]);

    $response->assertForbidden();
});

test('deactivated staff do not count toward subscription limits', function (): void {
    for ($i = 1; $i <= 2; $i++) {
        $s = User::create([
            'name' => "Staff {$i}",
            'email' => "inactive{$i}@test.test",
            'password' => Hash::make('Password123!'),
            'account_status' => 'active',
        ]);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $s->id,
            'role_id' => $this->staffRole->id,
            'status' => 'inactive',
        ]);
    }

    // Both are inactive, so only owner counts (1 of 3)
    $response = $this->withHeaders($this->ownerHeaders)
        ->postJson('/api/v1/owners/staff', [
            'name' => 'Allowed Staff',
            'email' => 'allowed@test.test',
        ]);

    $response->assertStatus(201);
});

// ──────────────────────────────────────────────────────────────
// Delete
// ──────────────────────────────────────────────────────────────

test('owner can remove a staff member', function (): void {
    $staff = User::create([
        'name' => 'Remove Me',
        'email' => 'remove@test.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $staff->id,
        'role_id' => $this->staffRole->id,
        'status' => 'active',
    ]);

    $response = $this->withHeaders($this->ownerHeaders)
        ->deleteJson("/api/v1/owners/staff/{$staff->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('tenant_users', [
        'tenant_id' => $this->tenant->id,
        'user_id' => $staff->id,
    ]);
});

test('owner cannot remove themselves', function (): void {
    $response = $this->withHeaders($this->ownerHeaders)
        ->deleteJson("/api/v1/owners/staff/{$this->owner->id}");

    $response->assertUnprocessable();
});
