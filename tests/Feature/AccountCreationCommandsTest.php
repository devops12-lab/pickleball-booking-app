<?php

use App\Models\Club;
use App\Models\ClubMember;
use App\Models\PlayerProfile;
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
});

/*
|--------------------------------------------------------------------------
| player:create
|--------------------------------------------------------------------------
*/
describe('player:create', function (): void {
    test('creates a new player with profile and statistics', function (): void {
        $this->artisan('player:create', [
            '--name' => 'Emma Player',
            '--email' => 'emma@test.com',
            '--password' => 'Password123!',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'emma@test.com']);
        $this->assertDatabaseHas('player_profiles', [
            'display_name' => 'Emma Player',
        ]);

        $user = User::where('email', 'emma@test.com')->first();
        $this->assertDatabaseHas('player_statistics', [
            'user_id' => $user->id,
            'current_rating' => 1200.00,
        ]);
    });

    test('warns when player profile already exists', function (): void {
        $user = User::create([
            'name' => 'Emma Player',
            'email' => 'emma@test.com',
            'password' => Hash::make('Password123!'),
            'account_status' => 'active',
        ]);
        PlayerProfile::create([
            'user_id' => $user->id,
            'display_name' => 'Emma Player',
        ]);

        $this->artisan('player:create', [
            '--name' => 'Emma Player',
            '--email' => 'emma@test.com',
            '--password' => 'Password123!',
        ])->assertSuccessful()
            ->expectsOutput('emma@test.com already has a player profile.');
    });

    test('fails with invalid email', function (): void {
        $this->artisan('player:create', [
            '--name' => 'Emma',
            '--email' => 'not-an-email',
            '--password' => 'Password123!',
        ])->assertFailed();
    });

    test('fails with short password', function (): void {
        $this->artisan('player:create', [
            '--name' => 'Emma',
            '--email' => 'emma@test.com',
            '--password' => 'short',
        ])->assertFailed();
    });
});

/*
|--------------------------------------------------------------------------
| owner:create
|--------------------------------------------------------------------------
*/
describe('owner:create', function (): void {
    test('creates a new owner with tenant', function (): void {
        $this->artisan('owner:create', [
            '--name' => 'Alice Owner',
            '--email' => 'alice@arena.test',
            '--password' => 'Password123!',
            '--business-name' => 'Alice Sports Hub',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'alice@arena.test']);
        $this->assertDatabaseHas('tenants', ['name' => 'Alice Sports Hub']);

        $user = User::where('email', 'alice@arena.test')->first();
        $ownerRole = Role::where('name', 'owner')->first();

        $this->assertDatabaseHas('tenant_users', [
            'user_id' => $user->id,
            'role_id' => $ownerRole->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('player_statistics', [
            'user_id' => $user->id,
        ]);
    });

    test('warns when user is already an owner', function (): void {
        $user = User::create([
            'name' => 'Alice Owner',
            'email' => 'alice@arena.test',
            'password' => Hash::make('Password123!'),
            'account_status' => 'active',
        ]);
        $ownerRole = Role::where('name', 'owner')->first();
        $tenant = Tenant::create([
            'name' => 'Existing Venue',
            'slug' => 'existing-venue',
            'business_email' => 'alice@arena.test',
            'status' => 'active',
        ]);
        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $ownerRole->id,
            'status' => 'active',
        ]);

        $this->artisan('owner:create', [
            '--name' => 'Alice Owner',
            '--email' => 'alice@arena.test',
            '--password' => 'Password123!',
            '--business-name' => 'Another Venue',
        ])->assertSuccessful()
            ->expectsOutput('alice@arena.test is already a facility owner.');
    });

    test('fails without business name', function (): void {
        $this->artisan('owner:create', [
            '--name' => 'Alice',
            '--email' => 'alice@arena.test',
            '--password' => 'Password123!',
            '--business-name' => '',
        ])->assertFailed();
    });
});

/*
|--------------------------------------------------------------------------
| staff:create
|--------------------------------------------------------------------------
*/
describe('staff:create', function (): void {
    test('creates a new staff member at a tenant', function (): void {
        $tenant = Tenant::create([
            'name' => 'Metro Club',
            'slug' => 'metro-club',
            'business_email' => 'info@metro.test',
            'status' => 'active',
        ]);

        $this->artisan('staff:create', [
            '--name' => 'Clara Staff',
            '--email' => 'clara@metro.test',
            '--password' => 'Password123!',
            '--tenant' => 'metro-club',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'clara@metro.test']);

        $user = User::where('email', 'clara@metro.test')->first();
        $staffRole = Role::where('name', 'staff')->first();

        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $staffRole->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('player_statistics', [
            'user_id' => $user->id,
        ]);
    });

    test('resolves tenant by numeric ID', function (): void {
        $tenant = Tenant::create([
            'name' => 'Metro Club',
            'slug' => 'metro-club',
            'business_email' => 'info@metro.test',
            'status' => 'active',
        ]);

        $this->artisan('staff:create', [
            '--name' => 'Clara Staff',
            '--email' => 'clara@metro.test',
            '--password' => 'Password123!',
            '--tenant' => (string) $tenant->id,
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'clara@metro.test']);
    });

    test('fails when tenant not found', function (): void {
        $this->artisan('staff:create', [
            '--name' => 'Clara Staff',
            '--email' => 'clara@metro.test',
            '--password' => 'Password123!',
            '--tenant' => 'nonexistent-tenant',
        ])->assertFailed();
    });

    test('warns when user is already staff at tenant', function (): void {
        $tenant = Tenant::create([
            'name' => 'Metro Club',
            'slug' => 'metro-club',
            'business_email' => 'info@metro.test',
            'status' => 'active',
        ]);
        $user = User::create([
            'name' => 'Clara Staff',
            'email' => 'clara@metro.test',
            'password' => Hash::make('Password123!'),
            'account_status' => 'active',
        ]);
        $staffRole = Role::where('name', 'staff')->first();
        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $staffRole->id,
            'status' => 'active',
        ]);

        $this->artisan('staff:create', [
            '--name' => 'Clara Staff',
            '--email' => 'clara@metro.test',
            '--password' => 'Password123!',
            '--tenant' => 'metro-club',
        ])->assertSuccessful()
            ->expectsOutput('clara@metro.test is already staff at "Metro Club".');
    });
});

/*
|--------------------------------------------------------------------------
| organizer:create
|--------------------------------------------------------------------------
*/
describe('organizer:create', function (): void {
    test('assigns an existing user as club organizer', function (): void {
        $user = User::create([
            'name' => 'Oscar Organizer',
            'email' => 'oscar@club.test',
            'password' => Hash::make('Password123!'),
            'account_status' => 'active',
        ]);
        $tenant = Tenant::create([
            'name' => 'Metro Club',
            'slug' => 'metro-club',
            'business_email' => 'info@metro.test',
            'status' => 'active',
        ]);
        $club = Club::create([
            'tenant_id' => $tenant->id,
            'creator_id' => $user->id,
            'name' => 'Downtown Dinking Club',
            'slug' => 'downtown-dinking-club',
            'privacy' => 'public',
        ]);

        $this->artisan('organizer:create', [
            '--email' => 'oscar@club.test',
            '--club' => 'downtown-dinking-club',
        ])->assertSuccessful();

        $this->assertDatabaseHas('club_members', [
            'club_id' => $club->id,
            'user_id' => $user->id,
            'role' => 'organizer',
            'status' => 'active',
        ]);
    });

    test('warns when user is already an organizer', function (): void {
        $user = User::create([
            'name' => 'Oscar Organizer',
            'email' => 'oscar@club.test',
            'password' => Hash::make('Password123!'),
            'account_status' => 'active',
        ]);
        $tenant = Tenant::create([
            'name' => 'Metro Club',
            'slug' => 'metro-club',
            'business_email' => 'info@metro.test',
            'status' => 'active',
        ]);
        $club = Club::create([
            'tenant_id' => $tenant->id,
            'creator_id' => $user->id,
            'name' => 'Downtown Dinking Club',
            'slug' => 'downtown-dinking-club',
            'privacy' => 'public',
        ]);
        ClubMember::create([
            'club_id' => $club->id,
            'user_id' => $user->id,
            'role' => 'organizer',
            'status' => 'active',
        ]);

        $this->artisan('organizer:create', [
            '--email' => 'oscar@club.test',
            '--club' => 'downtown-dinking-club',
        ])->assertSuccessful()
            ->expectsOutput('oscar@club.test is already an organizer of "Downtown Dinking Club".');
    });

    test('fails when user does not exist', function (): void {
        $tenant = Tenant::create([
            'name' => 'Metro Club',
            'slug' => 'metro-club',
            'business_email' => 'info@metro.test',
            'status' => 'active',
        ]);
        $user = User::create([
            'name' => 'Creator',
            'email' => 'creator@test.com',
            'password' => Hash::make('Password123!'),
            'account_status' => 'active',
        ]);
        Club::create([
            'tenant_id' => $tenant->id,
            'creator_id' => $user->id,
            'name' => 'Downtown Club',
            'slug' => 'downtown-club',
            'privacy' => 'public',
        ]);

        $this->artisan('organizer:create', [
            '--email' => 'nobody@test.com',
            '--club' => 'downtown-club',
        ])->assertFailed();
    });

    test('fails when club does not exist', function (): void {
        User::create([
            'name' => 'Oscar',
            'email' => 'oscar@club.test',
            'password' => Hash::make('Password123!'),
            'account_status' => 'active',
        ]);

        $this->artisan('organizer:create', [
            '--email' => 'oscar@club.test',
            '--club' => 'nonexistent-club',
        ])->assertFailed();
    });

    test('resolves club by numeric ID', function (): void {
        $user = User::create([
            'name' => 'Oscar Organizer',
            'email' => 'oscar@club.test',
            'password' => Hash::make('Password123!'),
            'account_status' => 'active',
        ]);
        $tenant = Tenant::create([
            'name' => 'Metro Club',
            'slug' => 'metro-club',
            'business_email' => 'info@metro.test',
            'status' => 'active',
        ]);
        $club = Club::create([
            'tenant_id' => $tenant->id,
            'creator_id' => $user->id,
            'name' => 'Downtown Club',
            'slug' => 'downtown-club',
            'privacy' => 'public',
        ]);

        $this->artisan('organizer:create', [
            '--email' => 'oscar@club.test',
            '--club' => (string) $club->id,
        ])->assertSuccessful();

        $this->assertDatabaseHas('club_members', [
            'club_id' => $club->id,
            'user_id' => $user->id,
            'role' => 'organizer',
        ]);
    });
});
