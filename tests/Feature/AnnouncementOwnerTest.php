<?php

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
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

    $this->ownerRole = Role::where('name', 'owner')->firstOrFail();

    $this->tenantA = Tenant::create([
        'name' => 'Metro Pickleball Club',
        'slug' => 'metro-pickleball',
        'business_email' => 'metro@courtflex.test',
        'status' => 'active',
    ]);

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

    $this->tenantB = Tenant::create([
        'name' => 'Sunset Pickleball Arena',
        'slug' => 'sunset-arena',
        'business_email' => 'sunset@courtflex.test',
        'status' => 'active',
    ]);

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

test('an owner can create an announcement scoped to their tenant', function (): void {
    $response = $this->actingAs($this->ownerA)
        ->withHeader('X-Tenant-ID', (string) $this->tenantA->id)
        ->postJson('/api/v1/owners/announcements', [
            'title' => 'Court 3 repainted',
            'body' => 'Court 3 reopens on Monday.',
            'type' => 'maintenance',
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.tenant_id', $this->tenantA->id)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.created_by', $this->ownerA->id);
});

test('an owner sees only their own tenant announcements', function (): void {
    Announcement::create(['title' => 'Metro news', 'body' => 'x', 'status' => 'published', 'tenant_id' => $this->tenantA->id]);
    Announcement::create(['title' => 'Sunset news', 'body' => 'x', 'status' => 'published', 'tenant_id' => $this->tenantB->id]);

    $this->actingAs($this->ownerA)
        ->withHeader('X-Tenant-ID', (string) $this->tenantA->id)
        ->getJson('/api/v1/owners/announcements')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.title', 'Metro news');
});

test('an owner cannot access another tenant announcement', function (): void {
    $foreign = Announcement::create([
        'title' => 'Sunset news',
        'body' => 'x',
        'status' => 'published',
        'tenant_id' => $this->tenantB->id,
    ]);

    $this->actingAs($this->ownerA)
        ->withHeader('X-Tenant-ID', (string) $this->tenantA->id)
        ->getJson("/api/v1/owners/announcements/{$foreign->id}")
        ->assertForbidden();

    $this->actingAs($this->ownerA)
        ->withHeader('X-Tenant-ID', (string) $this->tenantA->id)
        ->patchJson("/api/v1/owners/announcements/{$foreign->id}", ['title' => 'Hijacked'])
        ->assertForbidden();

    $this->actingAs($this->ownerA)
        ->withHeader('X-Tenant-ID', (string) $this->tenantA->id)
        ->deleteJson("/api/v1/owners/announcements/{$foreign->id}")
        ->assertForbidden();

    $this->actingAs($this->ownerA)
        ->withHeader('X-Tenant-ID', (string) $this->tenantA->id)
        ->postJson("/api/v1/owners/announcements/{$foreign->id}/publish")
        ->assertForbidden();

    expect($foreign->fresh()->title)->toBe('Sunset news');
});

test('an owner can publish and archive their own announcement', function (): void {
    $announcement = Announcement::create([
        'title' => 'Metro tournament',
        'body' => 'Registration opens Friday.',
        'status' => 'draft',
        'tenant_id' => $this->tenantA->id,
        'created_by' => $this->ownerA->id,
    ]);

    $this->actingAs($this->ownerA)
        ->withHeader('X-Tenant-ID', (string) $this->tenantA->id)
        ->postJson("/api/v1/owners/announcements/{$announcement->id}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    $this->actingAs($this->ownerA)
        ->withHeader('X-Tenant-ID', (string) $this->tenantA->id)
        ->postJson("/api/v1/owners/announcements/{$announcement->id}/archive")
        ->assertOk()
        ->assertJsonPath('data.status', 'archived');

    expect($announcement->fresh()->status)->toBe(AnnouncementStatus::Archived);
});

test('a player without an owner role cannot manage tenant announcements', function (): void {
    $player = User::create([
        'name' => 'Plain Player',
        'email' => 'plain@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $this->actingAs($player)
        ->withHeader('X-Tenant-ID', (string) $this->tenantA->id)
        ->getJson('/api/v1/owners/announcements')
        ->assertForbidden();

    $this->actingAs($player)
        ->withHeader('X-Tenant-ID', (string) $this->tenantA->id)
        ->postJson('/api/v1/owners/announcements', ['title' => 'x', 'body' => 'x'])
        ->assertForbidden();

    $this->assertDatabaseCount('announcements', 0);
});

test('a platform-wide announcement does not appear in an owner tenant list', function (): void {
    Announcement::create(['title' => 'Platform news', 'body' => 'x', 'status' => 'published']);

    $this->actingAs($this->ownerA)
        ->withHeader('X-Tenant-ID', (string) $this->tenantA->id)
        ->getJson('/api/v1/owners/announcements')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
