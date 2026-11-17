<?php

use App\Models\Club;
use App\Models\ClubMember;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->adminRole = Role::where('name', 'system_admin')->first();
    $this->clubManagerRole = Role::where('name', 'club_manager')->first();

    $this->tenant = Tenant::create([
        'name' => 'Phase2 Tenant',
        'slug' => 'phase2-tenant',
        'business_email' => 'phase2@courtflex.test',
        'status' => 'active',
    ]);

    $this->admin = User::create([
        'name' => 'System Admin',
        'email' => 'admin@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->admin->id,
        'role_id' => $this->adminRole->id,
        'status' => 'active',
    ]);

    $this->organizer = User::create([
        'name' => 'Club Organizer',
        'email' => 'organizer@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->organizer->id,
        'role_id' => $this->clubManagerRole->id,
        'status' => 'active',
    ]);

    $this->player = User::create([
        'name' => 'Regular Player',
        'email' => 'player@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $this->club = Club::create([
        'tenant_id' => $this->tenant->id,
        'creator_id' => $this->organizer->id,
        'name' => 'Test Club',
        'slug' => 'test-club',
        'privacy' => 'public',
        'status' => 'active',
        'max_members' => 50,
    ]);
});

// ──────────────────────────────────────────────────────────────
// Module 1: Club Policy — create restricted to admin/organizer
// ──────────────────────────────────────────────────────────────

test('player cannot create a club via organizer endpoint', function (): void {
    $token = $this->player->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->postJson('/api/v1/organizers/clubs', [
        'name' => 'Player Club',
        'privacy' => 'public',
    ]);

    $response->assertStatus(403);
});

test('admin can create a club via admin endpoint', function (): void {
    $token = $this->admin->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->postJson('/api/v1/admin/clubs', [
        'name' => 'Admin Created Club',
        'privacy' => 'public',
        'city' => 'Manila',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Admin Created Club');

    $this->assertDatabaseHas('clubs', ['name' => 'Admin Created Club']);
});

test('admin can update a club', function (): void {
    $token = $this->admin->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->patchJson("/api/v1/admin/clubs/{$this->club->id}/admin", [
        'name' => 'Updated Club Name',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    expect($this->club->fresh()->name)->toBe('Updated Club Name');
});

test('admin can delete a club', function (): void {
    $token = $this->admin->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->deleteJson("/api/v1/admin/clubs/{$this->club->id}");

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('clubs', ['id' => $this->club->id]);
});

test('non-admin cannot access admin club endpoints', function (): void {
    $token = $this->player->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->postJson('/api/v1/admin/clubs', [
        'name' => 'Unauthorized Club',
        'privacy' => 'public',
    ]);

    $response->assertStatus(403);
});

// ──────────────────────────────────────────────────────────────
// Module 4: Player club leave and available
// ──────────────────────────────────────────────────────────────

test('player can leave a club they joined', function (): void {
    ClubMember::create([
        'club_id' => $this->club->id,
        'user_id' => $this->player->id,
        'role' => 'member',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $token = $this->player->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->deleteJson("/api/v1/players/clubs/{$this->club->id}/leave");

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('club_members', [
        'club_id' => $this->club->id,
        'user_id' => $this->player->id,
    ]);
});

test('club creator cannot leave their own club', function (): void {
    ClubMember::create([
        'club_id' => $this->club->id,
        'user_id' => $this->organizer->id,
        'role' => 'manager',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $token = $this->organizer->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->deleteJson("/api/v1/players/clubs/{$this->club->id}/leave");

    $response->assertStatus(422);
});

test('player can list available clubs', function (): void {
    $token = $this->player->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->getJson('/api/v1/players/clubs/available');

    $response->assertOk()
        ->assertJsonPath('success', true);
});

test('max clubs per player is enforced from platform setting', function (): void {
    PlatformSetting::updateOrCreate(
        ['key' => 'max_clubs_per_player'],
        ['value' => '1', 'type' => 'integer', 'is_public' => true],
    );

    // Join the first club
    ClubMember::create([
        'club_id' => $this->club->id,
        'user_id' => $this->player->id,
        'role' => 'member',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    // Try to join a second club
    $secondClub = Club::create([
        'tenant_id' => $this->tenant->id,
        'creator_id' => $this->organizer->id,
        'name' => 'Second Club',
        'slug' => 'second-club',
        'privacy' => 'public',
        'status' => 'active',
    ]);

    $token = $this->player->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->postJson("/api/v1/players/clubs/{$secondClub->id}/join");

    $response->assertStatus(422)
        ->assertJsonPath('success', false);
});

// ──────────────────────────────────────────────────────────────
// Module 4: Tournament participant club representation
// ──────────────────────────────────────────────────────────────

test('tournament participant can have a club_id', function (): void {
    $tournament = Tournament::create([
        'tenant_id' => $this->tenant->id,
        'organizer_id' => $this->organizer->id,
        'name' => 'Club Tourney',
        'slug' => 'club-tourney',
        'format' => 'single_elimination',
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addDays(2),
        'status' => 'registration_open',
    ]);

    $participant = TournamentParticipant::create([
        'tournament_id' => $tournament->id,
        'user_id' => $this->player->id,
        'club_id' => $this->club->id,
        'status' => 'registered',
    ]);

    expect($participant->club_id)->toBe($this->club->id);
    expect($participant->club)->toBeInstanceOf(Club::class);
    expect($participant->club->name)->toBe('Test Club');
});

// ──────────────────────────────────────────────────────────────
// Module 3: Public tournaments include banner_image
// ──────────────────────────────────────────────────────────────

test('public tournament listing returns banner_image field', function (): void {
    Tournament::create([
        'tenant_id' => $this->tenant->id,
        'organizer_id' => $this->organizer->id,
        'name' => 'Banner Tourney',
        'slug' => 'banner-tourney',
        'format' => 'round_robin',
        'banner_image' => 'tournaments/banner123.jpg',
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addDays(2),
        'status' => 'registration_open',
    ]);

    $response = $this->getJson('/api/v1/public/tournaments');

    $response->assertOk()
        ->assertJsonPath('success', true);

    $data = $response->json('data');
    $bannerTourney = collect($data)->firstWhere('name', 'Banner Tourney');
    expect($bannerTourney['banner_image'])->toBe('tournaments/banner123.jpg');
});
