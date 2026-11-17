<?php

use App\Models\Advertisement;
use App\Models\AdvertisementRequest;
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

    $this->adminRole = Role::where('name', 'system_admin')->first();
    $this->ownerRole = Role::where('name', 'owner')->first();

    $this->tenant = Tenant::create([
        'name' => 'Ad Venue',
        'slug' => 'ad-venue',
        'business_email' => 'ad@courtflex.test',
        'status' => 'active',
    ]);

    $this->admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@ads.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->admin->id,
        'role_id' => $this->adminRole->id,
        'status' => 'active',
    ]);

    $this->owner = User::create([
        'name' => 'Owner',
        'email' => 'owner@ads.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->owner->id,
        'role_id' => $this->ownerRole->id,
        'status' => 'active',
    ]);
});

// ──────────────────────────────────────────────────────────────
// Admin Advertisement CRUD
// ──────────────────────────────────────────────────────────────

test('admin can create an advertisement', function (): void {
    $token = $this->admin->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->postJson('/api/v1/admin/advertisements', [
        'title' => 'Holiday Promo',
        'description' => 'Special holiday rates for all courts.',
        'type' => 'banner',
        'placement' => 'dashboard',
        'target_audience' => 'all',
        'start_date' => now()->addDay()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
        'priority' => 10,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', 'Holiday Promo')
        ->assertJsonPath('data.status', 'draft');

    $this->assertDatabaseHas('advertisements', [
        'title' => 'Holiday Promo',
        'type' => 'banner',
        'status' => 'draft',
    ]);
});

test('admin can list and filter advertisements', function (): void {
    Advertisement::create([
        'title' => 'Ad One',
        'type' => 'banner',
        'placement' => 'dashboard',
        'start_date' => now(),
        'end_date' => now()->addMonth(),
        'status' => 'published',
        'created_by' => $this->admin->id,
    ]);
    Advertisement::create([
        'title' => 'Ad Two',
        'type' => 'popup',
        'placement' => 'booking',
        'start_date' => now(),
        'end_date' => now()->addMonth(),
        'status' => 'draft',
        'created_by' => $this->admin->id,
    ]);

    $token = $this->admin->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->getJson('/api/v1/admin/advertisements?status=published');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('admin can publish and pause an advertisement', function (): void {
    $ad = Advertisement::create([
        'title' => 'Publish Test',
        'type' => 'sidebar',
        'placement' => 'discovery',
        'start_date' => now(),
        'end_date' => now()->addMonth(),
        'status' => 'draft',
        'created_by' => $this->admin->id,
    ]);

    $token = $this->admin->createToken('test')->plainTextToken;

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson("/api/v1/admin/advertisements/{$ad->id}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson("/api/v1/admin/advertisements/{$ad->id}/pause")
        ->assertOk()
        ->assertJsonPath('data.status', 'paused');
});

test('admin can delete an advertisement', function (): void {
    $ad = Advertisement::create([
        'title' => 'Delete Me',
        'type' => 'inline',
        'placement' => 'global',
        'start_date' => now(),
        'end_date' => now()->addMonth(),
        'status' => 'draft',
        'created_by' => $this->admin->id,
    ]);

    $token = $this->admin->createToken('test')->plainTextToken;

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->deleteJson("/api/v1/admin/advertisements/{$ad->id}")
        ->assertOk();

    $this->assertDatabaseMissing('advertisements', ['id' => $ad->id]);
});

// ──────────────────────────────────────────────────────────────
// Owner Advertisement Requests
// ──────────────────────────────────────────────────────────────

test('owner can submit an advertisement request', function (): void {
    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->postJson('/api/v1/owners/advertisement-requests', [
        'title' => 'Promote my venue',
        'description' => 'I want to promote my new facility on the platform.',
        'requested_placement' => 'dashboard',
        'contact_email' => 'owner@ads.test',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('advertisement_requests', [
        'requester_id' => $this->owner->id,
        'requester_type' => 'owner',
        'title' => 'Promote my venue',
    ]);
});

test('owner can list their advertisement requests', function (): void {
    AdvertisementRequest::create([
        'requester_id' => $this->owner->id,
        'requester_type' => 'owner',
        'tenant_id' => $this->tenant->id,
        'title' => 'Request 1',
        'description' => 'Test',
        'status' => 'pending',
    ]);

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->getJson('/api/v1/owners/advertisement-requests');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

// ──────────────────────────────────────────────────────────────
// Admin Reviews Advertisement Requests
// ──────────────────────────────────────────────────────────────

test('admin can review an advertisement request', function (): void {
    $adRequest = AdvertisementRequest::create([
        'requester_id' => $this->owner->id,
        'requester_type' => 'owner',
        'tenant_id' => $this->tenant->id,
        'title' => 'Review Me',
        'description' => 'Please review this request.',
        'status' => 'pending',
    ]);

    $token = $this->admin->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->patchJson("/api/v1/admin/advertisements/requests/{$adRequest->id}/review", [
        'status' => 'approved',
        'admin_response' => 'Approved. We will create the ad for you.',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $this->assertDatabaseHas('advertisement_requests', [
        'id' => $adRequest->id,
        'status' => 'approved',
        'reviewed_by' => $this->admin->id,
    ]);
});

test('non-admin cannot access advertisement management', function (): void {
    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->getJson('/api/v1/admin/advertisements');

    $response->assertStatus(403);
});

test('advertisement model scopes filter correctly', function (): void {
    Advertisement::create([
        'title' => 'Active Ad',
        'type' => 'banner',
        'placement' => 'dashboard',
        'target_audience' => 'players',
        'start_date' => now()->subDay(),
        'end_date' => now()->addMonth(),
        'status' => 'published',
        'created_by' => $this->admin->id,
    ]);
    Advertisement::create([
        'title' => 'Expired Ad',
        'type' => 'banner',
        'placement' => 'dashboard',
        'start_date' => now()->subMonth(),
        'end_date' => now()->subDay(),
        'status' => 'published',
        'created_by' => $this->admin->id,
    ]);

    expect(Advertisement::active()->count())->toBe(1);
    expect(Advertisement::active()->forPlacement('dashboard')->count())->toBe(1);
    expect(Advertisement::active()->forAudience('players')->count())->toBe(1);
    expect(Advertisement::active()->forAudience('owners')->count())->toBe(0);
});
