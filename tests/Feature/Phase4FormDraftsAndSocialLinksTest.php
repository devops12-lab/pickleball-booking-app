<?php

use App\Models\CourtFront;
use App\Models\FormDraft;
use App\Models\Role;
use App\Models\StorefrontSocialLink;
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

    $this->tenant = Tenant::create([
        'name' => 'Social Links Venue',
        'slug' => 'social-links-venue',
        'business_email' => 'venue@courtflex.test',
        'status' => 'active',
    ]);

    $this->owner = User::create([
        'name' => 'Venue Owner',
        'email' => 'owner@phase4.test',
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
// Module 6 — Form Drafts
// ──────────────────────────────────────────────────────────────

test('authenticated user can create a form draft', function (): void {
    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->postJson('/api/v1/form-drafts', [
        'form_type' => 'tournament_registration',
        'draft_data' => ['team_name' => 'Smashers', 'division' => 'open'],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.form_type', 'tournament_registration');

    $this->assertDatabaseHas('form_drafts', [
        'user_id' => $this->owner->id,
        'form_type' => 'tournament_registration',
    ]);
});

test('form draft rejects invalid form type', function (): void {
    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->postJson('/api/v1/form-drafts', [
        'form_type' => 'invalid_type',
        'draft_data' => ['foo' => 'bar'],
    ]);

    $response->assertStatus(422);
});

test('user can list and filter their own drafts', function (): void {
    FormDraft::create([
        'user_id' => $this->owner->id,
        'form_type' => 'tournament_registration',
        'draft_data' => ['team' => 'A'],
    ]);
    FormDraft::create([
        'user_id' => $this->owner->id,
        'form_type' => 'booking_request',
        'draft_data' => ['court' => 1],
    ]);

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->getJson('/api/v1/form-drafts?form_type=tournament_registration');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data.data');
});

test('user cannot access another user draft', function (): void {
    $other = User::create([
        'name' => 'Other User',
        'email' => 'other@phase4.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $draft = FormDraft::create([
        'user_id' => $other->id,
        'form_type' => 'club_membership',
        'draft_data' => ['club_id' => 1],
    ]);

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->getJson("/api/v1/form-drafts/{$draft->id}");

    $response->assertStatus(403);
});

test('user can update and delete their draft', function (): void {
    $draft = FormDraft::create([
        'user_id' => $this->owner->id,
        'form_type' => 'booking_request',
        'draft_data' => ['court' => 1],
    ]);

    $token = $this->owner->createToken('test')->plainTextToken;
    $headers = ['Authorization' => "Bearer {$token}"];

    $update = $this->withHeaders($headers)->patchJson("/api/v1/form-drafts/{$draft->id}", [
        'draft_data' => ['court' => 2, 'time' => '10:00'],
    ]);

    $update->assertOk()
        ->assertJsonPath('data.draft_data.court', 2);

    $delete = $this->withHeaders($headers)->deleteJson("/api/v1/form-drafts/{$draft->id}");

    $delete->assertOk();
    $this->assertDatabaseMissing('form_drafts', ['id' => $draft->id]);
});

// ──────────────────────────────────────────────────────────────
// Module 8 — Storefront Social Links
// ──────────────────────────────────────────────────────────────

test('owner can add a social link to their storefront', function (): void {
    CourtFront::create([
        'tenant_id' => $this->tenant->id,
        'is_open' => true,
    ]);

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->postJson('/api/v1/owners/storefront/social-links', [
        'platform' => 'facebook',
        'url' => 'https://facebook.com/myvenue',
        'display_name' => 'Our Facebook',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.platform', 'facebook');

    $this->assertDatabaseHas('storefront_social_links', [
        'platform' => 'facebook',
        'url' => 'https://facebook.com/myvenue',
    ]);
});

test('owner cannot exceed 5 active social links', function (): void {
    $front = CourtFront::create([
        'tenant_id' => $this->tenant->id,
        'is_open' => true,
    ]);

    foreach (['facebook', 'instagram', 'tiktok', 'youtube', 'linkedin'] as $i => $platform) {
        StorefrontSocialLink::create([
            'court_front_id' => $front->id,
            'platform' => $platform,
            'url' => "https://{$platform}.com/test",
            'sort_order' => $i,
            'is_active' => true,
        ]);
    }

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->postJson('/api/v1/owners/storefront/social-links', [
        'platform' => 'twitter',
        'url' => 'https://twitter.com/myvenue',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('owner can update a social link', function (): void {
    $front = CourtFront::create([
        'tenant_id' => $this->tenant->id,
        'is_open' => true,
    ]);

    $link = StorefrontSocialLink::create([
        'court_front_id' => $front->id,
        'platform' => 'instagram',
        'url' => 'https://instagram.com/old',
        'sort_order' => 0,
        'is_active' => true,
    ]);

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->patchJson("/api/v1/owners/storefront/social-links/{$link->id}", [
        'url' => 'https://instagram.com/newhandle',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.url', 'https://instagram.com/newhandle');
});

test('owner can reorder social links', function (): void {
    $front = CourtFront::create([
        'tenant_id' => $this->tenant->id,
        'is_open' => true,
    ]);

    $a = StorefrontSocialLink::create(['court_front_id' => $front->id, 'platform' => 'facebook', 'url' => 'https://fb.com/a', 'sort_order' => 0, 'is_active' => true]);
    $b = StorefrontSocialLink::create(['court_front_id' => $front->id, 'platform' => 'instagram', 'url' => 'https://ig.com/b', 'sort_order' => 1, 'is_active' => true]);

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->postJson('/api/v1/owners/storefront/social-links/reorder', [
        'order' => [$b->id, $a->id],
    ]);

    $response->assertOk();

    expect($a->fresh()->sort_order)->toBe(1);
    expect($b->fresh()->sort_order)->toBe(0);
});

test('public court front endpoint includes active social links', function (): void {
    $front = CourtFront::create([
        'tenant_id' => $this->tenant->id,
        'is_open' => true,
    ]);

    StorefrontSocialLink::create([
        'court_front_id' => $front->id,
        'platform' => 'facebook',
        'url' => 'https://facebook.com/venue',
        'sort_order' => 0,
        'is_active' => true,
    ]);
    StorefrontSocialLink::create([
        'court_front_id' => $front->id,
        'platform' => 'tiktok',
        'url' => 'https://tiktok.com/@venue',
        'sort_order' => 1,
        'is_active' => false,
    ]);

    $response = $this->getJson("/api/v1/public/tenants/{$this->tenant->id}/court-front");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data.social_links');
});
