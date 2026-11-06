<?php

use App\Models\Club;
use App\Models\ClubMember;
use App\Models\Court;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use Database\Seeders\PlatformAdminSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
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

    // 1. Tenant
    $this->tenant = Tenant::create([
        'name' => 'Metro Arena Club',
        'slug' => 'metro-arena-club',
        'business_email' => 'contact@metroarena.test',
        'status' => 'active',
    ]);

    // 2. Owner
    $this->owner = User::create([
        'name' => 'Olivia Owner',
        'email' => 'olivia@metroarena.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->owner->id,
        'role_id' => $this->ownerRole->id,
        'status' => 'active',
    ]);

    // 3. Facility & Courts
    $this->facility = Facility::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Metro Center Austin',
        'slug' => 'metro-center-austin',
        'address' => '100 Austin Way',
        'city' => 'Austin',
        'is_active' => true,
    ]);

    $this->court = Court::create([
        'facility_id' => $this->facility->id,
        'tenant_id' => $this->tenant->id,
        'name' => 'Center Court 1',
        'hourly_rate' => 500.00,
        'is_active' => true,
    ]);

    // 4. Player
    $this->player = User::create([
        'name' => 'Pete Player',
        'email' => 'pete@player.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    // 5. Club
    $this->club = Club::create([
        'tenant_id' => $this->tenant->id,
        'creator_id' => $this->owner->id,
        'name' => 'Austin Dinkers',
        'slug' => 'austin-dinkers',
        'privacy' => 'public',
        'max_members' => 30,
    ]);
    ClubMember::create([
        'club_id' => $this->club->id,
        'user_id' => $this->owner->id,
        'role' => 'manager',
        'status' => 'active',
    ]);
    ClubMember::create([
        'club_id' => $this->club->id,
        'user_id' => $this->player->id,
        'role' => 'member',
        'status' => 'active',
    ]);

    // 6. Tournament
    $this->tournament = Tournament::create([
        'tenant_id' => $this->tenant->id,
        'organizer_id' => $this->owner->id,
        'name' => 'Lone Star Pickleball Open',
        'slug' => 'lone-star-open-2026',
        'format' => 'single_elimination',
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addDays(2),
        'status' => 'upcoming',
    ]);
});

describe('Slug & ID Route Model Binding Audit', function (): void {
    test('public facility resolves by slug and numeric ID', function (): void {
        // By Slug
        $responseSlug = $this->getJson("/api/v1/public/facilities/{$this->facility->slug}");
        $responseSlug->assertOk()
            ->assertJsonPath('data.id', $this->facility->id)
            ->assertJsonPath('data.name', 'Metro Center Austin');

        // By ID
        $responseId = $this->getJson("/api/v1/public/facilities/{$this->facility->id}");
        $responseId->assertOk()
            ->assertJsonPath('data.id', $this->facility->id);
    });

    test('public club resolves by slug and numeric ID', function (): void {
        // By Slug
        $responseSlug = $this->getJson("/api/v1/public/clubs/{$this->club->slug}");
        $responseSlug->assertOk()
            ->assertJsonPath('data.id', $this->club->id)
            ->assertJsonPath('data.name', 'Austin Dinkers');

        // By ID
        $responseId = $this->getJson("/api/v1/public/clubs/{$this->club->id}");
        $responseId->assertOk()
            ->assertJsonPath('data.id', $this->club->id);
    });

    test('public tournament resolves by slug and numeric ID', function (): void {
        // By Slug
        $responseSlug = $this->getJson("/api/v1/public/tournaments/{$this->tournament->slug}");
        $responseSlug->assertOk()
            ->assertJsonPath('data.id', $this->tournament->id)
            ->assertJsonPath('data.name', 'Lone Star Pickleball Open');

        // By ID
        $responseId = $this->getJson("/api/v1/public/tournaments/{$this->tournament->id}");
        $responseId->assertOk()
            ->assertJsonPath('data.id', $this->tournament->id);
    });
});

describe('Owner Reports Database-Agnostic Audit', function (): void {
    test('owner reports endpoint succeeds without SQL function errors', function (): void {
        // Seed verified payment
        Payment::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->player->id,
            'amount' => 1500.00,
            'currency' => 'PHP',
            'payment_method' => 'bank_transfer',
            'transaction_reference' => 'PAY-TEST-12345',
            'status' => 'verified',
        ]);

        $token = $this->owner->createToken('owner_test_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenant->slug,
        ])->getJson('/api/v1/owners/reports');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'monthly_revenue',
                    'court_utilization',
                ],
            ]);
    });
});

describe('Tournament Match Cross-Tenant & Integrity Audit', function (): void {
    test('rejects submitting score for match belonging to another tournament', function (): void {
        $otherTournament = Tournament::create([
            'tenant_id' => $this->tenant->id,
            'organizer_id' => $this->owner->id,
            'name' => 'Other Tournament',
            'slug' => 'other-tournament',
            'format' => 'single_elimination',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addDays(2),
            'status' => 'upcoming',
        ]);

        $participant1 = TournamentParticipant::create([
            'tournament_id' => $otherTournament->id,
            'user_id' => $this->player->id,
            'status' => 'registered',
        ]);

        $matchOnOtherTournament = TournamentMatch::create([
            'tournament_id' => $otherTournament->id,
            'participant1_id' => $participant1->id,
            'status' => 'scheduled',
        ]);

        $token = $this->owner->createToken('organizer_token')->plainTextToken;

        // Attempt to submit score pointing to $this->tournament, but with match from $otherTournament
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v1/organizers/tournaments/{$this->tournament->id}/matches/{$matchOnOtherTournament->id}/results", [
            'score' => '11-9, 11-8',
            'winner_id' => $participant1->id,
        ]);

        $response->assertStatus(404);
    });

    test('valid match score submission completes match successfully', function (): void {
        $participant1 = TournamentParticipant::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $this->player->id,
            'status' => 'registered',
        ]);

        $match = TournamentMatch::create([
            'tournament_id' => $this->tournament->id,
            'participant1_id' => $participant1->id,
            'status' => 'scheduled',
        ]);

        $token = $this->owner->createToken('organizer_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v1/organizers/tournaments/{$this->tournament->id}/matches/{$match->id}/results", [
            'score' => '11-7, 11-5',
            'winner_id' => $participant1->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.score', '11-7, 11-5');

        expect($match->fresh()->status)->toBe('completed');
    });
});

describe('Player Clubs Resiliency Audit', function (): void {
    test('player clubs endpoint handles list and membership mappings cleanly', function (): void {
        $token = $this->player->createToken('player_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/v1/players/clubs');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Austin Dinkers')
            ->assertJsonPath('data.0.role', 'member');
    });
});
