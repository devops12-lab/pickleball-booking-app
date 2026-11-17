<?php

use App\Models\Advertisement;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AdvertisementDistributionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tenant = Tenant::create([
        'name' => 'Ad Dist Venue',
        'slug' => 'ad-dist-venue',
        'business_email' => 'dist@courtflex.test',
        'status' => 'active',
    ]);

    $this->admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@dist.test',
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
        'email' => 'player@dist.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $this->publishedAd = Advertisement::create([
        'title' => 'Dashboard Promo',
        'type' => 'banner',
        'placement' => 'dashboard',
        'target_audience' => 'all',
        'start_date' => now()->subDay(),
        'end_date' => now()->addMonth(),
        'status' => 'published',
        'destination_url' => 'https://courtflex.test/promo',
        'created_by' => $this->admin->id,
    ]);
});

// ──────────────────────────────────────────────────────────────
// Distribution Service
// ──────────────────────────────────────────────────────────────

test('distribution service returns active ads for placement', function (): void {
    $service = app(AdvertisementDistributionService::class);

    $ads = $service->getAdsForUser($this->player, 'dashboard');

    expect($ads)->toHaveCount(1);
    expect($ads->first()->title)->toBe('Dashboard Promo');
});

test('distribution service excludes expired ads', function (): void {
    Advertisement::create([
        'title' => 'Expired Ad',
        'type' => 'banner',
        'placement' => 'dashboard',
        'target_audience' => 'all',
        'start_date' => now()->subMonth(),
        'end_date' => now()->subDay(),
        'status' => 'published',
        'created_by' => $this->admin->id,
    ]);

    $service = app(AdvertisementDistributionService::class);
    $ads = $service->getAdsForUser($this->player, 'dashboard');

    expect($ads)->toHaveCount(1);
});

test('distribution service filters by audience', function (): void {
    Advertisement::create([
        'title' => 'Owners Only',
        'type' => 'sidebar',
        'placement' => 'dashboard',
        'target_audience' => 'owners',
        'start_date' => now()->subDay(),
        'end_date' => now()->addMonth(),
        'status' => 'published',
        'created_by' => $this->admin->id,
    ]);

    $service = app(AdvertisementDistributionService::class);

    // Player should see the "all" ad but not "owners" ad
    $ads = $service->getAdsForUser($this->player, 'dashboard');
    expect($ads)->toHaveCount(1);
    expect($ads->first()->title)->toBe('Dashboard Promo');
});

// ──────────────────────────────────────────────────────────────
// Player Ad Distribution Endpoint
// ──────────────────────────────────────────────────────────────

test('player can fetch ads for a placement', function (): void {
    $token = $this->player->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->getJson('/api/v1/players/advertisements?placement=dashboard');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('placement is required for ad fetch', function (): void {
    $token = $this->player->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->getJson('/api/v1/players/advertisements');

    $response->assertStatus(422);
});

// ──────────────────────────────────────────────────────────────
// Impression & Click Tracking
// ──────────────────────────────────────────────────────────────

test('authenticated user can record an ad impression', function (): void {
    $token = $this->player->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->postJson("/api/v1/advertisements/{$this->publishedAd->id}/impression", [
        'placement' => 'dashboard',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('advertisement_impressions', [
        'advertisement_id' => $this->publishedAd->id,
        'user_id' => $this->player->id,
        'placement' => 'dashboard',
    ]);

    expect($this->publishedAd->fresh()->impressions_count)->toBe(1);
});

test('duplicate impression within an hour is deduplicated', function (): void {
    $token = $this->player->createToken('test')->plainTextToken;
    $headers = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($headers)->postJson("/api/v1/advertisements/{$this->publishedAd->id}/impression", [
        'placement' => 'dashboard',
    ])->assertStatus(201);

    $this->withHeaders($headers)->postJson("/api/v1/advertisements/{$this->publishedAd->id}/impression", [
        'placement' => 'dashboard',
    ])->assertStatus(200);

    expect($this->publishedAd->fresh()->impressions_count)->toBe(1);
});

test('authenticated user can record an ad click', function (): void {
    $token = $this->player->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->postJson("/api/v1/advertisements/{$this->publishedAd->id}/click");

    $response->assertStatus(201)
        ->assertJsonPath('data.destination_url', 'https://courtflex.test/promo');

    $this->assertDatabaseHas('advertisement_clicks', [
        'advertisement_id' => $this->publishedAd->id,
        'user_id' => $this->player->id,
    ]);

    expect($this->publishedAd->fresh()->clicks_count)->toBe(1);
});

test('duplicate click within an hour is deduplicated', function (): void {
    $token = $this->player->createToken('test')->plainTextToken;
    $headers = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($headers)->postJson("/api/v1/advertisements/{$this->publishedAd->id}/click")->assertStatus(201);
    $this->withHeaders($headers)->postJson("/api/v1/advertisements/{$this->publishedAd->id}/click")->assertStatus(200);

    expect($this->publishedAd->fresh()->clicks_count)->toBe(1);
});
