<?php

use App\Models\Club;
use App\Models\ClubMember;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Auth\GoogleAuthenticationService;
use Database\Seeders\PlatformAdminSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlatformAdminSeeder::class);

    $this->ownerRole = Role::where('name', 'owner')->first();
    $this->staffRole = Role::where('name', 'staff')->first();
    $this->playerRole = Role::where('name', 'player')->first();
    $this->clubManagerRole = Role::where('name', 'club_manager')->first();

    // Default tenant for tests
    $this->tenant = Tenant::create([
        'name' => 'Metro Pickleball Club',
        'slug' => 'metro-pickleball-club',
        'business_email' => 'metro@courtflex.test',
        'status' => 'active',
    ]);
});

afterEach(function (): void {
    GoogleAuthenticationService::stopFaking();
});

/*
|--------------------------------------------------------------------------
| System Admin Authentication Tests
|--------------------------------------------------------------------------
*/
describe('System Admin Authentication', function (): void {
    test('system admin can login with valid credentials', function (): void {
        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'admin@courtflex.test',
            'password' => 'Password123!',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.active_role', 'system_admin')
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email', 'active_role', 'available_roles'],
                    'access_token',
                    'token_type',
                    'permissions',
                ],
            ]);
    });

    test('non-admin user is rejected from system admin portal', function (): void {
        User::create([
            'name' => 'Sneaky Player',
            'email' => 'sneaky@courtflex.test',
            'password' => Hash::make('Password123!'),
            'account_status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'sneaky@courtflex.test',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(403);
    });

    test('google authentication is strictly NOT available for system admin', function (): void {
        // Endpoint does not exist in routes
        $response = $this->postJson('/api/v1/admin/auth/google', [
            'id_token' => 'dummy-google-token',
        ]);

        $response->assertStatus(404);

        // Even if attempted programmatically, GoogleAuthenticationService blocks it
        GoogleAuthenticationService::fake([
            'sub' => 'google-admin-sub',
            'email' => 'admin@courtflex.test',
            'name' => 'Admin via Google',
        ]);

        $googleService = app(GoogleAuthenticationService::class);

        expect(fn () => $googleService->authenticate('mock-id-token', 'system_admin'))
            ->toThrow(AuthorizationException::class);
    });

    test('system admin can access me profile and logout', function (): void {
        $loginResponse = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'admin@courtflex.test',
            'password' => 'Password123!',
        ]);

        $token = $loginResponse->json('data.access_token');

        $meResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/auth/me');

        $meResponse->assertOk()
            ->assertJsonPath('data.user.email', 'admin@courtflex.test')
            ->assertJsonPath('data.user.active_role', 'system_admin');

        $logoutResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/auth/logout');

        $logoutResponse->assertOk()
            ->assertJsonPath('success', true);

        $admin = User::where('email', 'admin@courtflex.test')->first();
        expect($admin->tokens()->count())->toBe(0);
    });
});

/*
|--------------------------------------------------------------------------
| Facility Owner Authentication Tests
|--------------------------------------------------------------------------
*/
describe('Facility Owner Authentication', function (): void {
    test('facility owner can register with business and tenant details', function (): void {
        $response = $this->postJson('/api/v1/owners/auth/register', [
            'name' => 'Alice Arena Owner',
            'email' => 'alice@arena.test',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'business_name' => 'Alice Sports Hub',
            'business_phone' => '+1-555-123-4567',
            'address' => '123 Pickleball Way',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.active_role', 'owner')
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'access_token',
                    'token_type',
                    'context' => ['tenant_id', 'tenant_name'],
                ],
            ]);

        $this->assertDatabaseHas('users', ['email' => 'alice@arena.test']);
        $this->assertDatabaseHas('tenants', ['name' => 'Alice Sports Hub']);
    });

    test('facility owner can login with credentials', function (): void {
        $owner = User::create([
            'name' => 'Bob Owner',
            'email' => 'bob@club.test',
            'password' => Hash::make('Password123!'),
            'account_status' => 'active',
        ]);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $owner->id,
            'role_id' => $this->ownerRole->id,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/owners/auth/login', [
            'email' => 'bob@club.test',
            'password' => 'Password123!',
            'tenant_id' => $this->tenant->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.active_role', 'owner')
            ->assertJsonPath('data.context.tenant_id', $this->tenant->id);
    });

    test('facility owner can authenticate and onboard via Google OAuth', function (): void {
        GoogleAuthenticationService::fake([
            'sub' => 'google-owner-unique-id',
            'email' => 'owner.google@arena.test',
            'name' => 'Google Facility Owner',
            'picture' => 'https://example.com/avatar.jpg',
        ]);

        $response = $this->postJson('/api/v1/owners/auth/google', [
            'id_token' => 'valid-mock-google-id-token',
            'business_name' => 'Sunset Pickleball Club',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.active_role', 'owner');

        $this->assertDatabaseHas('users', ['email' => 'owner.google@arena.test']);
        $this->assertDatabaseHas('tenants', ['name' => 'Sunset Pickleball Club']);
        $this->assertDatabaseHas('user_auth_providers', [
            'provider' => 'google',
            'provider_user_id' => 'google-owner-unique-id',
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Facility Staff Authentication Tests
|--------------------------------------------------------------------------
*/
describe('Facility Staff Authentication', function (): void {
    test('uninvited user cannot register or login as staff via Google', function (): void {
        GoogleAuthenticationService::fake([
            'sub' => 'google-stranger-id',
            'email' => 'stranger@nowhere.test',
            'name' => 'Random Stranger',
        ]);

        $response = $this->postJson('/api/v1/staff/auth/google', [
            'id_token' => 'valid-mock-google-id-token',
        ]);

        $response->assertStatus(403);
    });

    test('owner-created staff credentials allow staff login', function (): void {
        $staffUser = User::create([
            'name' => 'Clara Staff',
            'email' => 'clara.staff@metro.test',
            'password' => Hash::make('Password123!'),
            'account_status' => 'active',
            'email_verified_at' => now(),
        ]);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $staffUser->id,
            'role_id' => $this->staffRole->id,
            'position' => 'front_desk',
            'status' => 'active',
        ]);

        $loginResponse = $this->postJson('/api/v1/staff/auth/login', [
            'email' => 'clara.staff@metro.test',
            'password' => 'Password123!',
            'tenant_id' => $this->tenant->id,
        ]);

        $loginResponse->assertOk()
            ->assertJsonPath('data.user.active_role', 'staff');
    });

    test('existing staff can authenticate via Google', function (): void {
        $staffUser = User::create([
            'name' => 'Dave Desk',
            'email' => 'dave@metro.test',
            'password' => Hash::make('Password123!'),
            'account_status' => 'active',
        ]);
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $staffUser->id,
            'role_id' => $this->staffRole->id,
            'status' => 'active',
        ]);

        GoogleAuthenticationService::fake([
            'sub' => 'google-dave-id',
            'email' => 'dave@metro.test',
            'name' => 'Dave Desk Google',
        ]);

        $response = $this->postJson('/api/v1/staff/auth/google', [
            'id_token' => 'valid-mock-google-id-token',
            'tenant_id' => $this->tenant->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.active_role', 'staff');
    });
});

/*
|--------------------------------------------------------------------------
| Player Authentication Tests
|--------------------------------------------------------------------------
*/
describe('Player Authentication', function (): void {
    test('player can register globally and login', function (): void {
        $response = $this->postJson('/api/v1/players/auth/register', [
            'name' => 'Emma Player',
            'email' => 'emma@courtflex.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.active_role', 'player');

        $this->assertDatabaseHas('users', ['email' => 'emma@courtflex.test']);

        $loginResponse = $this->postJson('/api/v1/players/auth/login', [
            'email' => 'emma@courtflex.test',
            'password' => 'Password123!',
        ]);

        $loginResponse->assertOk()
            ->assertJsonPath('data.user.active_role', 'player');
    });

    test('player can authenticate via Google OAuth seamlessly', function (): void {
        GoogleAuthenticationService::fake([
            'sub' => 'google-player-12345',
            'email' => 'emma.google@gmail.com',
            'name' => 'Emma Google Player',
        ]);

        $response = $this->postJson('/api/v1/players/auth/google', [
            'id_token' => 'valid-mock-google-id-token',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.active_role', 'player');

        $this->assertDatabaseHas('users', ['email' => 'emma.google@gmail.com']);
        $this->assertDatabaseHas('user_auth_providers', [
            'provider' => 'google',
            'provider_user_id' => 'google-player-12345',
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Club Manager / Organizer Authentication Tests
|--------------------------------------------------------------------------
*/
describe('Club Organizer Authentication', function (): void {
    test('assigned club organizer can login and access organizer portal', function (): void {
        $user = User::create([
            'name' => 'Oscar Organizer',
            'email' => 'oscar@club.test',
            'password' => Hash::make('Password123!'),
            'account_status' => 'active',
        ]);

        $club = Club::create([
            'tenant_id' => $this->tenant->id,
            'creator_id' => $user->id,
            'name' => 'Downtown Dinking Club',
            'slug' => 'downtown-dinking-club',
            'privacy' => 'public',
        ]);

        ClubMember::create([
            'club_id' => $club->id,
            'user_id' => $user->id,
            'role' => 'manager',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/organizers/auth/login', [
            'email' => 'oscar@club.test',
            'password' => 'Password123!',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.active_role', 'club_manager');
    });

    test('unassigned user cannot login via organizer Google endpoint', function (): void {
        GoogleAuthenticationService::fake([
            'sub' => 'google-unassigned-id',
            'email' => 'stranger.organizer@gmail.com',
            'name' => 'Stranger Organizer',
        ]);

        $response = $this->postJson('/api/v1/organizers/auth/google', [
            'id_token' => 'valid-mock-google-id-token',
        ]);

        $response->assertStatus(403);
    });
});
