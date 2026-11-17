<?php

use App\Models\PlatformSetting;
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

    $this->tenant = Tenant::create([
        'name' => 'Settings Venue',
        'slug' => 'settings-venue',
        'business_email' => 'settings@courtflex.test',
        'status' => 'active',
    ]);

    $this->admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@settings.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->admin->id,
        'role_id' => Role::where('name', 'system_admin')->first()->id,
        'status' => 'active',
    ]);

    $this->player = User::create([
        'name' => 'Player',
        'email' => 'player@settings.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->player->id,
        'role_id' => Role::where('name', 'player')->first()->id,
        'status' => 'active',
    ]);

    $this->adminToken = $this->admin->createToken('test')->plainTextToken;
    $this->playerToken = $this->player->createToken('test')->plainTextToken;

    // Seed the app configuration settings
    PlatformSetting::firstOrCreate(['key' => 'app_name'], ['value' => 'CourtFlex', 'type' => 'string', 'is_public' => true]);
    PlatformSetting::firstOrCreate(['key' => 'app_description'], ['value' => 'Pickleball platform', 'type' => 'string', 'is_public' => true]);
    PlatformSetting::firstOrCreate(['key' => 'platform_socials'], ['value' => '[]', 'type' => 'json', 'is_public' => true]);
    PlatformSetting::firstOrCreate(['key' => 'maintenance_mode'], ['value' => '0', 'type' => 'boolean', 'is_public' => true]);
    PlatformSetting::firstOrCreate(['key' => 'default_timezone'], ['value' => 'Asia/Manila', 'type' => 'string', 'is_public' => false]);
});

// ──────────────────────────────────────────────────────────────
// App Settings CRUD
// ──────────────────────────────────────────────────────────────

test('admin can retrieve app settings', function (): void {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->adminToken}",
    ])->getJson('/api/v1/admin/settings/app');

    $response->assertOk()
        ->assertJsonPath('data.app_name', 'CourtFlex');
});

test('admin can update app settings', function (): void {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->adminToken}",
    ])->putJson('/api/v1/admin/settings/app', [
        'app_name' => 'CourtFlex Pro',
        'maintenance_mode' => true,
    ]);

    $response->assertOk();

    expect(PlatformSetting::get('app_name'))->toBe('CourtFlex Pro');
    expect(PlatformSetting::get('maintenance_mode'))->toBeTrue();
});

test('non-admin cannot update app settings', function (): void {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->playerToken}",
    ])->putJson('/api/v1/admin/settings/app', [
        'app_name' => 'Hacked',
    ]);

    $response->assertForbidden();
});

test('app settings validation rejects invalid data', function (): void {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->adminToken}",
    ])->putJson('/api/v1/admin/settings/app', [
        'default_timezone' => 'Invalid/Zone',
        'default_currency' => 'TOOLONG',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['default_timezone', 'default_currency']);
});

// ──────────────────────────────────────────────────────────────
// Social Links
// ──────────────────────────────────────────────────────────────

test('admin can add a social link', function (): void {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->adminToken}",
    ])->postJson('/api/v1/admin/settings/socials', [
        'platform' => 'facebook',
        'url' => 'https://facebook.com/courtflex',
        'display_name' => 'CourtFlex FB',
    ]);

    $response->assertStatus(201);

    $socials = PlatformSetting::get('platform_socials');
    expect($socials)->toHaveCount(1);
    expect($socials[0]['platform'])->toBe('facebook');
});

test('admin can update a social link by index', function (): void {
    // Seed a social link first
    PlatformSetting::where('key', 'platform_socials')->update([
        'value' => json_encode([['platform' => 'facebook', 'url' => 'https://facebook.com/old', 'display_name' => null]]),
    ]);

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->adminToken}",
    ])->putJson('/api/v1/admin/settings/socials/0', [
        'platform' => 'instagram',
        'url' => 'https://instagram.com/courtflex',
        'display_name' => 'CourtFlex IG',
    ]);

    $response->assertOk();

    $socials = PlatformSetting::get('platform_socials');
    expect($socials[0]['platform'])->toBe('instagram');
});

test('admin can remove a social link by index', function (): void {
    PlatformSetting::where('key', 'platform_socials')->update([
        'value' => json_encode([
            ['platform' => 'facebook', 'url' => 'https://facebook.com/cf', 'display_name' => null],
            ['platform' => 'instagram', 'url' => 'https://instagram.com/cf', 'display_name' => null],
        ]),
    ]);

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->adminToken}",
    ])->deleteJson('/api/v1/admin/settings/socials/0');

    $response->assertOk();

    $socials = PlatformSetting::get('platform_socials');
    expect($socials)->toHaveCount(1);
    expect($socials[0]['platform'])->toBe('instagram');
});

test('removing a non-existent social link returns 404', function (): void {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->adminToken}",
    ])->deleteJson('/api/v1/admin/settings/socials/99');

    $response->assertNotFound();
});

// ──────────────────────────────────────────────────────────────
// Public App Config
// ──────────────────────────────────────────────────────────────

test('public app-config returns only public settings', function (): void {
    $response = $this->getJson('/api/v1/public/app-config');

    $response->assertOk()
        ->assertJsonPath('data.app_name', 'CourtFlex')
        ->assertJsonMissing(['default_timezone']);
});
