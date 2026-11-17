<?php

use App\Models\OpenPlayParticipant;
use App\Models\OpenPlayQueueGame;
use App\Models\OpenPlaySession;
use App\Models\Tenant;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Results Test Venue',
        'slug' => 'results-test-venue',
        'business_email' => 'results@courtflex.test',
        'status' => 'active',
    ]);

    $this->player1 = User::create([
        'name' => 'Alice Champion',
        'email' => 'alice@results.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $this->player2 = User::create([
        'name' => 'Bob Runner',
        'email' => 'bob@results.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $this->player3 = User::create([
        'name' => 'Carol Third',
        'email' => 'carol@results.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
});

// ──────────────────────────────────────────────────────────────
// Tournament Results
// ──────────────────────────────────────────────────────────────

test('public can view completed tournament results', function (): void {
    $tournament = Tournament::factory()->completed()->create([
        'tenant_id' => $this->tenant->id,
        'venue_id' => $this->tenant->id,
        'entry_fee' => 500.00,
        'max_teams' => 16,
    ]);

    $champion = TournamentParticipant::create([
        'tournament_id' => $tournament->id,
        'user_id' => $this->player1->id,
        'seed' => 1,
        'status' => 'confirmed',
    ]);

    $runnerUp = TournamentParticipant::create([
        'tournament_id' => $tournament->id,
        'user_id' => $this->player2->id,
        'seed' => 2,
        'status' => 'eliminated',
    ]);

    $thirdPlace = TournamentParticipant::create([
        'tournament_id' => $tournament->id,
        'user_id' => $this->player3->id,
        'seed' => 3,
        'status' => 'eliminated',
    ]);

    $tournament->update([
        'champion_participant_id' => $champion->id,
        'runner_up_participant_id' => $runnerUp->id,
        'third_place_participant_id' => $thirdPlace->id,
    ]);

    $response = $this->getJson("/api/v1/public/tournaments/{$tournament->id}/results");

    $response->assertOk()
        ->assertJsonPath('data.tournament.status', 'completed')
        ->assertJsonCount(3, 'data.placements')
        ->assertJsonPath('data.placements.0.place', 1)
        ->assertJsonPath('data.placements.1.place', 2)
        ->assertJsonPath('data.placements.2.place', 3);
});

test('tournament results returns 404 for non-completed tournament', function (): void {
    $tournament = Tournament::factory()->inProgress()->create([
        'tenant_id' => $this->tenant->id,
        'venue_id' => $this->tenant->id,
        'entry_fee' => 500.00,
        'max_teams' => 16,
    ]);

    $response = $this->getJson("/api/v1/public/tournaments/{$tournament->id}/results");

    $response->assertNotFound();
});

test('tournament results omits null placements gracefully', function (): void {
    $tournament = Tournament::factory()->completed()->create([
        'tenant_id' => $this->tenant->id,
        'venue_id' => $this->tenant->id,
        'entry_fee' => 500.00,
        'max_teams' => 16,
    ]);

    $champion = TournamentParticipant::create([
        'tournament_id' => $tournament->id,
        'user_id' => $this->player1->id,
        'seed' => 1,
        'status' => 'confirmed',
    ]);

    $tournament->update([
        'champion_participant_id' => $champion->id,
    ]);

    $response = $this->getJson("/api/v1/public/tournaments/{$tournament->id}/results");

    $response->assertOk()
        ->assertJsonCount(1, 'data.placements')
        ->assertJsonPath('data.placements.0.place', 1);
});

// ──────────────────────────────────────────────────────────────
// Open Play Results
// ──────────────────────────────────────────────────────────────

test('public can view completed open play session results', function (): void {
    $session = OpenPlaySession::factory()->completed()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $p1 = OpenPlayParticipant::create([
        'open_play_session_id' => $session->id,
        'user_id' => $this->player1->id,
        'status' => 'completed',
        'games_played' => 3,
    ]);

    $p2 = OpenPlayParticipant::create([
        'open_play_session_id' => $session->id,
        'user_id' => $this->player2->id,
        'status' => 'completed',
        'games_played' => 3,
    ]);

    $p3 = OpenPlayParticipant::create([
        'open_play_session_id' => $session->id,
        'user_id' => $this->player3->id,
        'status' => 'completed',
        'games_played' => 2,
    ]);

    // Game 1: p1 wins (team_a)
    OpenPlayQueueGame::create([
        'open_play_session_id' => $session->id,
        'court_id' => $session->court_id,
        'team_a' => [$p1->id],
        'team_b' => [$p2->id],
        'winner' => 'a',
        'started_at' => now()->subHour(),
        'ended_at' => now()->subMinutes(45),
    ]);

    // Game 2: p1 wins again (team_b this time)
    OpenPlayQueueGame::create([
        'open_play_session_id' => $session->id,
        'court_id' => $session->court_id,
        'team_a' => [$p3->id],
        'team_b' => [$p1->id],
        'winner' => 'b',
        'started_at' => now()->subMinutes(40),
        'ended_at' => now()->subMinutes(25),
    ]);

    // Game 3: p2 wins
    OpenPlayQueueGame::create([
        'open_play_session_id' => $session->id,
        'court_id' => $session->court_id,
        'team_a' => [$p2->id],
        'team_b' => [$p3->id],
        'winner' => 'a',
        'started_at' => now()->subMinutes(20),
        'ended_at' => now()->subMinutes(5),
    ]);

    $response = $this->getJson("/api/v1/public/open-play/{$session->id}/results");

    $response->assertOk()
        ->assertJsonPath('data.session.status', 'completed')
        ->assertJsonCount(3, 'data.placements')
        ->assertJsonPath('data.placements.0.place', 1)
        ->assertJsonPath('data.placements.0.wins', 2)
        ->assertJsonPath('data.placements.1.place', 2)
        ->assertJsonPath('data.placements.1.wins', 1);
});

test('open play results returns 404 for non-completed session', function (): void {
    $session = OpenPlaySession::factory()->inProgress()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $response = $this->getJson("/api/v1/public/open-play/{$session->id}/results");

    $response->assertNotFound();
});

test('open play results returns empty placements when no games played', function (): void {
    $session = OpenPlaySession::factory()->completed()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $response = $this->getJson("/api/v1/public/open-play/{$session->id}/results");

    $response->assertOk()
        ->assertJsonCount(0, 'data.placements');
});
