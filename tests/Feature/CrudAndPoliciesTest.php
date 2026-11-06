<?php

use App\Models\Club;
use App\Models\ClubMember;
use App\Models\Court;
use App\Models\Facility;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Tournament;
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
    $this->clubManagerRole = Role::where('name', 'club_manager')->first();

    $this->tenant = Tenant::create([
        'name' => 'CRUD Tenant',
        'slug' => 'crud-tenant',
        'business_email' => 'crud@courtflex.test',
        'status' => 'active',
    ]);

    $this->owner = User::create([
        'name' => 'CRUD Owner',
        'email' => 'owner@crud.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->owner->id,
        'role_id' => $this->ownerRole->id,
        'status' => 'active',
    ]);

    $this->facility = Facility::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'CRUD Facility',
        'slug' => 'crud-facility',
        'address' => '123 Facility Rd',
        'city' => 'Manila',
        'is_active' => true,
    ]);

    $this->court = Court::create([
        'facility_id' => $this->facility->id,
        'tenant_id' => $this->tenant->id,
        'name' => 'Court Alpha',
        'hourly_rate' => 300.00,
        'is_active' => true,
    ]);

    $this->club = Club::create([
        'tenant_id' => $this->tenant->id,
        'creator_id' => $this->owner->id,
        'name' => 'Smashers Club',
        'slug' => 'smashers-club',
        'privacy' => 'public',
        'max_members' => 40,
    ]);
    ClubMember::create([
        'club_id' => $this->club->id,
        'user_id' => $this->owner->id,
        'role' => 'manager',
        'status' => 'active',
    ]);

    $this->tournament = Tournament::create([
        'tenant_id' => $this->tenant->id,
        'organizer_id' => $this->owner->id,
        'name' => 'Spring Smash Open',
        'slug' => 'spring-smash-open',
        'format' => 'single_elimination',
        'starts_at' => now()->addWeeks(2),
        'ends_at' => now()->addWeeks(2)->addDays(2),
        'status' => 'upcoming',
    ]);
});

test('owner can delete court under own facility', function (): void {
    $token = $this->owner->createToken('owner_token')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->deleteJson("/api/v1/owners/courts/{$this->court->id}");

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('courts', ['id' => $this->court->id]);
});

test('owner can delete facility under own tenant', function (): void {
    $token = $this->owner->createToken('owner_token')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->deleteJson("/api/v1/owners/facilities/{$this->facility->id}");

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('facilities', ['id' => $this->facility->id]);
});

test('organizer can update club details', function (): void {
    $token = $this->owner->createToken('organizer_token')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->patchJson("/api/v1/organizers/clubs/{$this->club->id}", [
        'name' => 'Renamed Smashers Club',
        'description' => 'Updated club description',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Renamed Smashers Club');

    expect($this->club->fresh()->name)->toBe('Renamed Smashers Club');
});

test('organizer can delete club they created', function (): void {
    $token = $this->owner->createToken('organizer_token')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->deleteJson("/api/v1/organizers/clubs/{$this->club->id}");

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('clubs', ['id' => $this->club->id]);
});

test('organizer can delete tournament they created', function (): void {
    $token = $this->owner->createToken('organizer_token')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->deleteJson("/api/v1/organizers/tournaments/{$this->tournament->id}");

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('tournaments', ['id' => $this->tournament->id]);
});
