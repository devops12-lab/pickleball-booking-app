<?php

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\User;
use Database\Seeders\PlatformAdminSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlatformAdminSeeder::class);

    $this->admin = User::where('email', 'admin@courtflex.test')->firstOrFail();

    $this->player = User::create([
        'name' => 'Plain Player',
        'email' => 'plain@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
});

test('an administrator can create a draft announcement', function (): void {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/announcements', [
            'title' => 'Welcome to CourtFlex',
            'body' => 'The platform is now live.',
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.tenant_id', null)
        ->assertJsonPath('data.type', 'general')
        ->assertJsonPath('data.audience', 'all');

    expect($this->admin->id)->toBe(Announcement::query()->latest('id')->firstOrFail()->created_by);
});

test('a future publish time schedules the announcement', function (): void {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/announcements', [
            'title' => 'Maintenance window',
            'body' => 'Bookings are paused overnight.',
            'type' => 'maintenance',
            'publish_at' => now()->addDay()->toIso8601String(),
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.published_at', null);

    expect(Announcement::query()->latest('id')->firstOrFail()->published_at)->toBeNull();
});

test('a past publish time publishes the announcement immediately', function (): void {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/announcements', [
            'title' => 'New ranking system',
            'body' => 'Rankings recalculate nightly.',
            'publish_at' => now()->subHour()->toIso8601String(),
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.published_at', fn (string $publishedAt) => $publishedAt !== null);
});

test('an administrator can list and filter announcements', function (): void {
    Announcement::create(['title' => 'Draft one', 'body' => 'x', 'status' => 'draft']);
    Announcement::create(['title' => 'Live one', 'body' => 'x', 'status' => 'published', 'published_at' => now()]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/announcements')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/announcements?status=published')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Live one');

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/announcements?search=live')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('an administrator can update a draft', function (): void {
    $announcement = Announcement::create(['title' => 'Old title', 'body' => 'old', 'status' => 'draft']);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/announcements/{$announcement->id}", [
            'title' => 'New title',
            'body' => 'new body',
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'New title')
        ->assertJsonPath('data.status', 'draft');
});

test('an administrator cannot edit an archived announcement', function (): void {
    $announcement = Announcement::create(['title' => 'Gone', 'body' => 'x', 'status' => 'archived']);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/announcements/{$announcement->id}", ['title' => 'Zombie'])
        ->assertUnprocessable()
        ->assertJsonPath('success', false);
});

test('an administrator can publish a draft and archive a published announcement', function (): void {
    $announcement = Announcement::create(['title' => 'Season start', 'body' => 'x', 'status' => 'draft']);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/announcements/{$announcement->id}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    expect($announcement->fresh()->published_at)->not->toBeNull();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/announcements/{$announcement->id}/archive")
        ->assertOk()
        ->assertJsonPath('data.status', 'archived');
});

test('publishing an archived announcement is rejected', function (): void {
    $announcement = Announcement::create(['title' => 'Done', 'body' => 'x', 'status' => 'archived']);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/announcements/{$announcement->id}/publish")
        ->assertUnprocessable()
        ->assertJsonPath('success', false);

    expect($announcement->fresh()->status)->toBe(AnnouncementStatus::Archived);
});

test('an administrator can delete an announcement', function (): void {
    $announcement = Announcement::create(['title' => 'Remove me', 'body' => 'x', 'status' => 'published']);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/announcements/{$announcement->id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);
});

test('announcement validation rejects invalid payloads', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/announcements', ['title' => '', 'body' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'body']);

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/announcements', ['title' => 'x', 'body' => 'x', 'type' => 'spam'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/announcements', [
            'title' => 'x',
            'body' => 'x',
            'publish_at' => now()->addDay()->toIso8601String(),
            'expires_at' => now()->toIso8601String(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expires_at');

    $this->assertDatabaseCount('announcements', 0);
});

test('guests and players cannot manage announcements', function (): void {
    $this->getJson('/api/v1/admin/announcements')->assertUnauthorized();
    $this->postJson('/api/v1/admin/announcements', ['title' => 'x', 'body' => 'x'])->assertUnauthorized();

    $this->actingAs($this->player)
        ->getJson('/api/v1/admin/announcements')
        ->assertForbidden();

    $this->actingAs($this->player)
        ->postJson('/api/v1/admin/announcements', ['title' => 'x', 'body' => 'x'])
        ->assertForbidden();

    $this->assertDatabaseCount('announcements', 0);
});

test('the publish-due command promotes scheduled announcements only', function (): void {
    Announcement::create(['title' => 'Due now', 'body' => 'x', 'status' => 'scheduled', 'publish_at' => now()->subMinutes(5)]);
    Announcement::create(['title' => 'Due later', 'body' => 'x', 'status' => 'scheduled', 'publish_at' => now()->addDay()]);
    Announcement::create(['title' => 'Still draft', 'body' => 'x', 'status' => 'draft', 'publish_at' => now()->subMinutes(5)]);

    $this->artisan('announcements:publish-due')
        ->expectsOutputToContain('Published 1 scheduled announcement(s).')
        ->assertSuccessful();

    $due = Announcement::query()->where('title', 'Due now')->firstOrFail();

    expect($due->status)->toBe(AnnouncementStatus::Published)
        ->and($due->published_at->equalTo($due->publish_at))->toBeTrue()
        ->and(Announcement::query()->where('title', 'Due later')->firstOrFail()->status)->toBe(AnnouncementStatus::Scheduled)
        ->and(Announcement::query()->where('title', 'Still draft')->firstOrFail()->status)->toBe(AnnouncementStatus::Draft);
});
