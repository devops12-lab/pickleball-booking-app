<?php

use App\Models\Announcement;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->player = User::create([
        'name' => 'Pia Player',
        'email' => 'pia@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $this->otherPlayer = User::create([
        'name' => 'Oscar Other',
        'email' => 'oscar@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $this->tenantA = Tenant::create([
        'name' => 'Metro Pickleball Club',
        'slug' => 'metro-pickleball',
        'business_email' => 'metro@courtflex.test',
        'status' => 'active',
    ]);

    $this->tenantB = Tenant::create([
        'name' => 'Sunset Pickleball Arena',
        'slug' => 'sunset-arena',
        'business_email' => 'sunset@courtflex.test',
        'status' => 'active',
    ]);
});

/**
 * Give the player a club membership at a tenant (mimics booking/club links).
 */
function joinTenantClub(Tenant $tenant, User $user): void
{
    $club = Club::create([
        'tenant_id' => $tenant->id,
        'creator_id' => $user->id,
        'name' => "Club of {$tenant->slug}",
        'slug' => 'club-'.Str::random(8),
    ]);

    ClubMember::create([
        'club_id' => $club->id,
        'user_id' => $user->id,
        'role' => 'member',
        'status' => 'active',
    ]);
}

test('a player sees published platform-wide announcements', function (): void {
    Announcement::create(['title' => 'Platform live', 'body' => 'x', 'status' => 'published', 'published_at' => now()]);

    $this->actingAs($this->player)
        ->getJson('/api/v1/players/announcements')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Platform live')
        ->assertJsonPath('data.0.is_read', false)
        ->assertJsonPath('meta.unread_count', 1);
});

test('a player never sees drafts, scheduled or archived announcements', function (): void {
    Announcement::create(['title' => 'Draft thing', 'body' => 'x', 'status' => 'draft']);
    Announcement::create(['title' => 'Future thing', 'body' => 'x', 'status' => 'scheduled', 'publish_at' => now()->addDay()]);
    Announcement::create(['title' => 'Old thing', 'body' => 'x', 'status' => 'archived']);

    $this->actingAs($this->player)
        ->getJson('/api/v1/players/announcements')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});

test('a player only sees announcements targeted at players', function (): void {
    Announcement::create(['title' => 'For everyone', 'body' => 'x', 'status' => 'published', 'audience' => 'all', 'published_at' => now()]);
    Announcement::create(['title' => 'For players', 'body' => 'x', 'status' => 'published', 'audience' => 'players', 'published_at' => now()]);
    Announcement::create(['title' => 'For staff', 'body' => 'x', 'status' => 'published', 'audience' => 'staff', 'published_at' => now()]);
    Announcement::create(['title' => 'For owners', 'body' => 'x', 'status' => 'published', 'audience' => 'owners', 'published_at' => now()]);

    $this->actingAs($this->player)
        ->getJson('/api/v1/players/announcements')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('a player sees tenant announcements only for tenants they belong to', function (): void {
    Announcement::create([
        'title' => 'Metro promo',
        'body' => 'x',
        'status' => 'published',
        'audience' => 'players',
        'tenant_id' => $this->tenantA->id,
        'published_at' => now(),
    ]);

    Announcement::create([
        'title' => 'Sunset promo',
        'body' => 'x',
        'status' => 'published',
        'audience' => 'players',
        'tenant_id' => $this->tenantB->id,
        'published_at' => now(),
    ]);

    joinTenantClub($this->tenantA, $this->player);

    $response = $this->actingAs($this->player)
        ->getJson('/api/v1/players/announcements')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Metro promo');

    // A player without any membership sees neither tenant announcement.
    $this->actingAs($this->otherPlayer)
        ->getJson('/api/v1/players/announcements')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('expired announcements are hidden', function (): void {
    Announcement::create(['title' => 'Still fresh', 'body' => 'x', 'status' => 'published', 'expires_at' => now()->addDay(), 'published_at' => now()]);
    Announcement::create(['title' => 'Expired', 'body' => 'x', 'status' => 'published', 'expires_at' => now()->subHour(), 'published_at' => now()->subDay()]);

    $this->actingAs($this->player)
        ->getJson('/api/v1/players/announcements')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Still fresh');
});

test('pinned announcements are ordered first', function (): void {
    Announcement::create(['title' => 'Regular old', 'body' => 'x', 'status' => 'published', 'published_at' => now()->subHours(2)]);
    Announcement::create(['title' => 'Pinned', 'body' => 'x', 'status' => 'published', 'is_pinned' => true, 'published_at' => now()->subDays(3)]);
    Announcement::create(['title' => 'Regular new', 'body' => 'x', 'status' => 'published', 'published_at' => now()]);

    $this->actingAs($this->player)
        ->getJson('/api/v1/players/announcements')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Pinned')
        ->assertJsonPath('data.1.title', 'Regular new')
        ->assertJsonPath('data.2.title', 'Regular old');
});

test('a player can read a visible announcement but gets 404 for hidden ones', function (): void {
    $visible = Announcement::create(['title' => 'Visible', 'body' => 'x', 'status' => 'published', 'published_at' => now()]);
    $draft = Announcement::create(['title' => 'Hidden draft', 'body' => 'x', 'status' => 'draft']);
    $foreign = Announcement::create([
        'title' => 'Hidden tenant',
        'body' => 'x',
        'status' => 'published',
        'tenant_id' => $this->tenantB->id,
        'published_at' => now(),
    ]);

    $this->actingAs($this->player)
        ->getJson("/api/v1/players/announcements/{$visible->id}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Visible');

    $this->actingAs($this->player)->getJson("/api/v1/players/announcements/{$draft->id}")->assertNotFound();
    $this->actingAs($this->player)->getJson("/api/v1/players/announcements/{$foreign->id}")->assertNotFound();
});

test('marking an announcement read is idempotent and updates unread count', function (): void {
    $first = Announcement::create(['title' => 'First', 'body' => 'x', 'status' => 'published', 'published_at' => now()]);
    $second = Announcement::create(['title' => 'Second', 'body' => 'x', 'status' => 'published', 'published_at' => now()]);

    $this->actingAs($this->player)
        ->getJson('/api/v1/players/announcements/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread', 2);

    $this->actingAs($this->player)
        ->postJson("/api/v1/players/announcements/{$first->id}/read")
        ->assertOk()
        ->assertJsonPath('data.is_read', true);

    // Repeat the read call — no duplicate, count unchanged.
    $this->actingAs($this->player)
        ->postJson("/api/v1/players/announcements/{$first->id}/read")
        ->assertOk();

    $this->assertDatabaseCount('announcement_reads', 1);

    $this->actingAs($this->player)
        ->getJson('/api/v1/players/announcements/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread', 1);

    $this->actingAs($this->player)
        ->getJson("/api/v1/players/announcements/{$second->id}")
        ->assertOk()
        ->assertJsonPath('data.is_read', false);
});

test('marking a hidden announcement as read is rejected', function (): void {
    $draft = Announcement::create(['title' => 'Hidden draft', 'body' => 'x', 'status' => 'draft']);

    $this->actingAs($this->player)
        ->postJson("/api/v1/players/announcements/{$draft->id}/read")
        ->assertNotFound();

    $this->assertDatabaseCount('announcement_reads', 0);
});

test('guests cannot read the announcement feed', function (): void {
    $this->getJson('/api/v1/players/announcements')->assertUnauthorized();
    $this->getJson('/api/v1/players/announcements/unread-count')->assertUnauthorized();
});
