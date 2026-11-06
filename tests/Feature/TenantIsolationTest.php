<?php

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

    // Tenant A
    $this->tenantA = Tenant::create([
        'name' => 'Metro Pickleball Club',
        'slug' => 'metro-pickleball',
        'business_email' => 'metro@courtflex.test',
        'status' => 'active',
    ]);

    // Owner A
    $this->ownerA = User::create([
        'name' => 'Owner Alice',
        'email' => 'alice@metro.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenantA->id,
        'user_id' => $this->ownerA->id,
        'role_id' => $this->ownerRole->id,
        'status' => 'active',
    ]);

    // Tenant B
    $this->tenantB = Tenant::create([
        'name' => 'Sunset Pickleball Arena',
        'slug' => 'sunset-arena',
        'business_email' => 'sunset@courtflex.test',
        'status' => 'active',
    ]);

    // Owner B
    $this->ownerB = User::create([
        'name' => 'Owner Bob',
        'email' => 'bob@sunset.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenantB->id,
        'user_id' => $this->ownerB->id,
        'role_id' => $this->ownerRole->id,
        'status' => 'active',
    ]);
});

test('owner can create facility in their own tenant', function (): void {
    $response = $this->actingAs($this->ownerA)
        ->withHeader('X-Tenant-ID', (string) $this->tenantA->id)
        ->postJson('/api/v1/owners/facilities', [
            'name' => 'Metro Arena 1',
            'address' => '123 Main St',
            'city' => 'Manila',
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Metro Arena 1')
        ->assertJsonPath('data.tenant_id', $this->tenantA->id);

    $this->assertDatabaseHas('facilities', [
        'tenant_id' => $this->tenantA->id,
        'name' => 'Metro Arena 1',
    ]);
});

test('owner cannot create facility in another tenant', function (): void {
    // Owner A attempts to access Tenant B with X-Tenant-ID: Tenant B
    $response = $this->actingAs($this->ownerA)
        ->withHeader('X-Tenant-ID', (string) $this->tenantB->id)
        ->postJson('/api/v1/owners/facilities', [
            'name' => 'Malicious Arena',
            'address' => '456 Hack Rd',
            'city' => 'Manila',
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    $this->assertDatabaseMissing('facilities', [
        'name' => 'Malicious Arena',
    ]);
});

test('staff without facility management permission cannot create facility', function (): void {
    $staff = User::create([
        'name' => 'Staff Steve',
        'email' => 'steve@metro.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    TenantUser::create([
        'tenant_id' => $this->tenantA->id,
        'user_id' => $staff->id,
        'role_id' => $this->staffRole->id,
        'status' => 'active',
    ]);

    // Staff tries to access owner endpoint
    $response = $this->actingAs($staff)
        ->withHeader('X-Tenant-ID', (string) $this->tenantA->id)
        ->postJson('/api/v1/owners/facilities', [
            'name' => 'Unauthorized Arena',
            'address' => '123 Main St',
            'city' => 'Manila',
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);
});

test('player can discover public facilities across tenants', function (): void {
    Facility::create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Metro Arena',
        'slug' => 'metro-arena',
        'address' => '123 St',
        'city' => 'Metro City',
        'is_active' => true,
    ]);

    Facility::create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Sunset Court Center',
        'slug' => 'sunset-center',
        'address' => '789 St',
        'city' => 'Sunset City',
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/public/facilities');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data');
});
