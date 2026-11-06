<?php

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Database\Seeders\PlatformAdminSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlatformAdminSeeder::class);

    $this->ownerRole = Role::where('name', 'owner')->first();
    $this->staffRole = Role::where('name', 'staff')->first();
    $this->clubManagerRole = Role::where('name', 'club_manager')->first();

    // Tenant 1
    $this->tenant1 = Tenant::create([
        'name' => 'BGC Arena',
        'slug' => 'bgc-arena',
        'business_email' => 'bgc@courtflex.test',
        'status' => 'active',
    ]);

    // Owner 1
    $this->owner1 = User::create([
        'name' => 'Owner Olivia',
        'email' => 'olivia@bgc.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant1->id,
        'user_id' => $this->owner1->id,
        'role_id' => $this->ownerRole->id,
        'status' => 'active',
    ]);

    // Staff 1
    $this->staff1 = User::create([
        'name' => 'Staff Sam',
        'email' => 'sam@bgc.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant1->id,
        'user_id' => $this->staff1->id,
        'role_id' => $this->staffRole->id,
        'status' => 'active',
    ]);

    // General Player
    $this->player = User::create([
        'name' => 'Player Pete',
        'email' => 'pete@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    // Club Manager / Organizer
    $this->organizer = User::create([
        'name' => 'Organizer Oscar',
        'email' => 'oscar@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant1->id,
        'user_id' => $this->organizer->id,
        'role_id' => $this->clubManagerRole->id,
        'status' => 'active',
    ]);

    // System Admin
    $this->admin = User::where('email', 'admin@courtflex.test')->first();
});

test('public api endpoints are accessible unauthenticated under /api/v1/public/*', function (): void {
    $this->getJson('/api/v1/public/facilities')
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->getJson('/api/v1/public/courts')
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->getJson('/api/v1/public/clubs')
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->getJson('/api/v1/public/open-play')
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->getJson('/api/v1/public/tournaments')
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('player can access /api/v1/players/* endpoints', function (): void {
    $this->actingAs($this->player)
        ->getJson('/api/v1/players/dashboard')
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($this->player)
        ->getJson('/api/v1/players/profile')
        ->assertOk()
        ->assertJsonPath('data.email', 'pete@courtflex.test');
});

test('player cannot access /api/v1/owners/* or /api/v1/admin/* (403 Forbidden)', function (): void {
    // Attempt owner endpoint
    $this->actingAs($this->player)
        ->withHeader('X-Tenant-ID', (string) $this->tenant1->id)
        ->getJson('/api/v1/owners/dashboard')
        ->assertStatus(403)
        ->assertJsonPath('success', false);

    // Attempt admin endpoint
    $this->actingAs($this->player)
        ->getJson('/api/v1/admin/dashboard')
        ->assertStatus(403)
        ->assertJsonPath('success', false);
});

test('owner can access /api/v1/owners/* for their own tenant', function (): void {
    $this->actingAs($this->owner1)
        ->withHeader('X-Tenant-ID', (string) $this->tenant1->id)
        ->getJson('/api/v1/owners/dashboard')
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('staff can access /api/v1/staff/* for their assigned tenant', function (): void {
    $this->actingAs($this->staff1)
        ->withHeader('X-Tenant-ID', (string) $this->tenant1->id)
        ->getJson('/api/v1/staff/dashboard')
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('staff cannot access /api/v1/admin/* (403 Forbidden)', function (): void {
    $this->actingAs($this->staff1)
        ->getJson('/api/v1/admin/dashboard')
        ->assertStatus(403)
        ->assertJsonPath('success', false);
});

test('system admin can access /api/v1/admin/* endpoints', function (): void {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/dashboard')
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/tenants')
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('organizer can access /api/v1/organizers/* endpoints', function (): void {
    $this->actingAs($this->organizer)
        ->getJson('/api/v1/organizers/dashboard')
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('multi-role user (Owner + Player) can access both /players/* and authorized /owners/*', function (): void {
    // Owner is also a Player globally
    $this->actingAs($this->owner1)
        ->getJson('/api/v1/players/profile')
        ->assertOk()
        ->assertJsonPath('data.email', 'olivia@bgc.test');

    $this->actingAs($this->owner1)
        ->withHeader('X-Tenant-ID', (string) $this->tenant1->id)
        ->getJson('/api/v1/owners/dashboard')
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('unauthenticated requests to protected endpoints return 401', function (): void {
    $this->getJson('/api/v1/players/profile')
        ->assertStatus(401)
        ->assertJsonPath('success', false);

    $this->getJson('/api/v1/admin/dashboard')
        ->assertStatus(401)
        ->assertJsonPath('success', false);

    $this->getJson('/api/v1/owners/dashboard')
        ->assertStatus(401)
        ->assertJsonPath('success', false);
});
