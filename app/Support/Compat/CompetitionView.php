<?php

namespace App\Support\Compat;

use App\Models\Club;
use App\Models\Court;
use App\Models\GeoLocation;
use App\Models\PlayerProfile;
use App\Models\PlayerStatistic;
use App\Models\Tenant;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tournament and geographic-ranking contract shapes
 * (packages/api/src/tournament-types.ts and ranking-types.ts).
 *
 * A tournament is hosted by a venue tenant or a community club, but is always
 * played at a venue tenant. The draw lives in tournament_matches; a participant
 * is one singles player or one doubles team.
 */
class CompetitionView
{
    public const MINIMUM_MATCHES = 5;

    /** @var list<string> */
    public const STATUSES = ['registration', 'in_progress', 'completed', 'cancelled'];

    /** @var list<string> */
    public const FORMATS = ['single_elimination', 'round_robin', 'pool_knockout'];

    /** @var list<string> */
    public const SCOPES = ['barangay', 'city', 'province', 'country', 'global'];

    /** @var array<string, int> */
    private static array $ratingCache = [];

    /**
     * @return array<string, mixed>
     */
    public static function summary(Tournament $tournament, ?int $viewerId = null): array
    {
        $tournament->loadMissing([
            'tenant.venueProfile',
            'venue.venueProfile',
            'participants.user',
            'participants.partner',
        ]);

        $entries = self::activeEntries($tournament);
        $venue = $tournament->venue ?? $tournament->tenant;
        $host = self::host($tournament, $venue);
        $champion = $tournament->participants->firstWhere('id', $tournament->champion_participant_id);

        return [
            'id' => (string) $tournament->id,
            'slug' => (string) $tournament->slug,
            'name' => (string) $tournament->name,
            'host' => $host,
            'venue' => [
                'id' => (string) ($venue?->id ?? $tournament->tenant_id),
                'name' => (string) ($venue?->name ?? 'Venue'),
                'city' => (string) ($venue?->venueProfile?->city ?? ''),
            ],
            'format' => self::format($tournament->format),
            'match_type' => self::matchType($tournament->match_type),
            'status' => self::status($tournament->status),
            'starts_at' => self::iso($tournament->starts_at),
            'registration_closes_at' => self::iso(self::registrationClosesAt($tournament)),
            'entries_count' => $entries->count(),
            'capacity' => self::capacity($tournament),
            'entry_fee' => Contract::money($tournament->entry_fee),
            'champion' => self::entryRef($champion),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Tournament $tournament, ?int $viewerId = null): array
    {
        $tournament->loadMissing([
            'tenant.venueProfile',
            'venue.venueProfile',
            'participants.user',
            'participants.partner',
            'matches.participant1.user',
            'matches.participant1.partner',
            'matches.participant2.user',
            'matches.participant2.partner',
            'matches.court',
            'matches.game.court',
        ]);

        $entries = self::activeEntries($tournament)
            ->sort(function (TournamentParticipant $a, TournamentParticipant $b) use ($tournament): int {
                $seedA = $a->seed ?? PHP_INT_MAX;
                $seedB = $b->seed ?? PHP_INT_MAX;
                if ($seedA !== $seedB) {
                    return $seedA <=> $seedB;
                }

                return self::entryRating($b, self::matchType($tournament->match_type))
                    <=> self::entryRating($a, self::matchType($tournament->match_type));
            })
            ->values();

        $mine = self::myEntry($tournament, $viewerId);
        $block = self::registrationBlock($tournament, $viewerId);

        return self::summary($tournament, $viewerId) + [
            'description' => (string) ($tournament->description ?? ''),
            'game' => self::game($tournament->game),
            'courts' => self::courts($tournament),
            'pools' => $tournament->pools === null ? null : (int) $tournament->pools,
            'entries' => $entries->map(fn (TournamentParticipant $entry): array => self::entry($entry, $tournament))->all(),
            'matches' => self::matchViews($tournament),
            'standings' => self::standings($tournament),
            'my_entry_id' => $mine === null ? null : (string) $mine->id,
            'can_manage' => self::canManage($tournament, $viewerId),
            'can_register' => $block === null,
            'register_note' => $mine === null ? $block : null,
            'cancel_reason' => $tournament->cancel_reason,
        ];
    }

    public static function status(mixed $status): string
    {
        return match ((string) $status) {
            'registration', 'registration_open', 'upcoming' => 'registration',
            'in_progress', 'live' => 'in_progress',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            default => 'registration',
        };
    }

    public static function format(mixed $format): string
    {
        $format = (string) $format;

        // The older backend accepts double_elimination, but the SPA's draw
        // contract only has these three formats.
        return in_array($format, self::FORMATS, true) ? $format : 'single_elimination';
    }

    public static function matchType(mixed $type): string
    {
        return (string) $type === 'singles' ? 'singles' : 'doubles';
    }

    /**
     * @return array{target: int, win_by: int, best_of: int, scoring: string}
     */
    public static function game(mixed $game): array
    {
        $stored = is_array($game) ? $game : [];
        $target = (int) ($stored['target'] ?? 11);
        $bestOf = (int) ($stored['best_of'] ?? 1);

        return [
            'target' => in_array($target, [11, 15, 21], true) ? $target : 11,
            'win_by' => 2,
            'best_of' => in_array($bestOf, [1, 3], true) ? $bestOf : 1,
            'scoring' => ($stored['scoring'] ?? 'side_out') === 'rally' ? 'rally' : 'side_out',
        ];
    }

    public static function registrationClosesAt(Tournament $tournament): CarbonImmutable
    {
        return CarbonImmutable::instance($tournament->registration_deadline ?? $tournament->starts_at ?? now());
    }

    public static function capacity(Tournament $tournament): int
    {
        // Seed-era rows have capacity 8 (the column default) but max_teams 32.
        // New rows write both values, so max() still gives the intended field.
        return max((int) ($tournament->capacity ?? 0), (int) ($tournament->max_teams ?? 0), 4);
    }

    /**
     * @return EloquentCollection<int, TournamentParticipant>
     */
    public static function activeEntries(Tournament $tournament): EloquentCollection
    {
        $tournament->loadMissing(['participants.user', 'participants.partner']);

        return $tournament->participants
            ->filter(fn (TournamentParticipant $entry): bool => ($entry->status ?: 'registered') !== 'withdrawn')
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public static function entry(TournamentParticipant $entry, Tournament $tournament): array
    {
        return [
            'id' => (string) $entry->id,
            'name' => self::entryName($entry),
            'seed' => $entry->seed,
            'players' => self::entryPlayers($entry),
            'rating' => self::entryRating($entry, self::matchType($tournament->match_type)),
        ];
    }

    /**
     * @return array{id: string, name: string, seed: int|null}|null
     */
    public static function entryRef(?TournamentParticipant $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        return [
            'id' => (string) $entry->id,
            'name' => self::entryName($entry),
            'seed' => $entry->seed,
        ];
    }

    public static function entryName(TournamentParticipant $entry): string
    {
        $parts = collect([$entry->user, $entry->partner])
            ->filter()
            ->map(function (User $user): string {
                $name = trim((string) $user->name);
                $first = preg_split('/\s+/', $name)[0] ?? $name;

                return $first !== '' ? $first : $name;
            })
            ->filter()
            ->values()
            ->all();

        return $parts === [] ? 'Entry '.$entry->id : implode(' & ', $parts);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public static function entryPlayers(TournamentParticipant $entry): array
    {
        return collect([$entry->user, $entry->partner])
            ->filter()
            ->map(fn (User $user): array => ['id' => (string) $user->id, 'name' => (string) $user->name])
            ->values()
            ->all();
    }

    public static function entryRating(TournamentParticipant $entry, string $type): int
    {
        if ($entry->rating !== null) {
            return (int) $entry->rating;
        }

        $ratings = collect([$entry->user, $entry->partner])
            ->filter()
            ->map(fn (User $user): int => self::ratingFor((int) $user->id, $type))
            ->all();

        return $ratings === []
            ? RatingRules::current()['initial']
            : (int) round(array_sum($ratings) / count($ratings));
    }

    public static function ratingFor(int $userId, string $type): int
    {
        $key = $type.':'.$userId;

        if (array_key_exists($key, self::$ratingCache)) {
            return self::$ratingCache[$key];
        }

        $stat = PlayerStatistic::query()->where('user_id', $userId)->first();
        $value = $type === 'singles'
            ? ($stat?->singles_rating ?? $stat?->current_rating)
            : ($stat?->doubles_rating ?? $stat?->current_rating);

        return self::$ratingCache[$key] = (int) round((float) ($value ?? RatingRules::current()['initial']));
    }

    /**
     * @return array<string, string>
     */
    public static function host(Tournament $tournament, ?Tenant $venue = null): array
    {
        if (($tournament->host_type ?: 'venue') === 'community' && $tournament->host_id !== null) {
            $club = Club::query()->find($tournament->host_id);

            if ($club !== null) {
                return ['type' => 'community', 'id' => (string) $club->id, 'name' => (string) $club->name];
            }
        }

        return [
            'type' => 'venue',
            'id' => (string) ($tournament->host_id ?? $tournament->tenant_id),
            'name' => (string) ($venue?->name ?? 'Venue'),
        ];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public static function courts(Tournament $tournament): array
    {
        $ids = is_array($tournament->court_ids)
            ? array_values(array_filter(array_map('intval', $tournament->court_ids)))
            : [];

        if ($ids === []) {
            $ids = Court::query()
                ->where('tenant_id', $tournament->venue_id ?? $tournament->tenant_id)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        if ($ids === []) {
            return [];
        }

        $courts = Court::query()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)
            ->map(fn (int $id): ?array => $courts->has($id)
                ? ['id' => (string) $id, 'name' => (string) $courts[$id]->name]
                : null)
            ->filter()
            ->values()
            ->all();
    }

    public static function canManage(Tournament $tournament, ?int $viewerId): bool
    {
        if ($viewerId === null) {
            return false;
        }

        $user = User::query()->find($viewerId);

        if ($user === null) {
            return false;
        }

        if ($user->isSystemAdmin() || (int) $tournament->organizer_id === $viewerId) {
            return true;
        }

        $hostType = $tournament->host_type ?: 'venue';
        $hostId = (int) ($tournament->host_id ?? $tournament->tenant_id);

        if ($hostType === 'community') {
            $club = Club::query()->find($hostId);

            return $club !== null && CommunityView::canManageCommunity($club, $viewerId);
        }

        return self::canManageVenue($hostId, $viewerId);
    }

    public static function canManageVenue(int $tenantId, int $viewerId): bool
    {
        return DB::table('tenant_users')
            ->join('roles', 'roles.id', '=', 'tenant_users.role_id')
            ->where('tenant_users.tenant_id', $tenantId)
            ->where('tenant_users.user_id', $viewerId)
            ->where('tenant_users.status', 'active')
            ->whereIn('roles.name', ['owner', 'club_manager', 'staff'])
            ->exists();
    }

    /**
     * Venues and clubs the viewer runs. A venue hosts at itself; a club uses
     * its home venue, which may still be null.
     *
     * @return list<array{type: string, id: string, name: string, venue_id: string|null}>
     */
    public static function hostOptions(User $user): array
    {
        $all = $user->isSystemAdmin();

        $venues = Tenant::query()
            ->with(['venueProfile', 'subscription'])
            ->where('slug', '!=', 'platform-admin')
            ->where('status', 'active')
            ->get()
            ->filter(function (Tenant $tenant) use ($all, $user): bool {
                $club = Contract::club($tenant);

                return $club['status'] === 'active'
                    && ! $club['read_only']
                    && ($all || self::canManageVenue((int) $tenant->id, (int) $user->id));
            })
            ->map(fn (Tenant $tenant): array => [
                'type' => 'venue',
                'id' => (string) $tenant->id,
                'name' => (string) $tenant->name,
                'venue_id' => (string) $tenant->id,
            ]);

        $communities = Club::query()
            ->where('status', 'active')
            ->get()
            ->filter(fn (Club $club): bool => $all || CommunityView::canManageCommunity($club, (int) $user->id))
            ->map(fn (Club $club): array => [
                'type' => 'community',
                'id' => (string) $club->id,
                'name' => (string) $club->name,
                'venue_id' => $club->home_venue_id === null ? null : (string) $club->home_venue_id,
            ]);

        return $venues->concat($communities)->values()->all();
    }

    public static function registrationBlock(Tournament $tournament, ?int $viewerId): ?string
    {
        if (self::status($tournament->status) !== 'registration') {
            return 'Registration is closed.';
        }

        if (self::registrationClosesAt($tournament)->lessThan(CarbonImmutable::now())) {
            return 'Registration has closed.';
        }

        $active = self::activeEntries($tournament);

        if ($active->count() >= self::capacity($tournament)) {
            return 'The draw is full.';
        }

        if ($viewerId === null) {
            return 'Sign in to register.';
        }

        if (! PlayerProfile::query()->where('user_id', $viewerId)->exists()) {
            return 'Set up a player profile to register.';
        }

        if ($active->contains(fn (TournamentParticipant $entry): bool => (int) $entry->user_id === $viewerId
            || (int) $entry->partner_id === $viewerId)) {
            return "You're registered.";
        }

        return null;
    }

    public static function myEntry(Tournament $tournament, ?int $viewerId): ?TournamentParticipant
    {
        if ($viewerId === null) {
            return null;
        }

        return self::activeEntries($tournament)->first(
            fn (TournamentParticipant $entry): bool => (int) $entry->user_id === $viewerId
                || (int) $entry->partner_id === $viewerId,
        );
    }

    /**
     * The draw, grouped by stage/pool. Round robin has one null pool; pool play
     * has one table per pool letter; knockout matches do not produce tables.
     *
     * @return list<array{pool: string|null, rows: list<array<string, mixed>>}>
     */
    public static function standings(Tournament $tournament): array
    {
        if (self::status($tournament->status) === 'registration') {
            return [];
        }

        $matches = $tournament->matches
            ->filter(fn (TournamentMatch $match): bool => in_array($match->stage, ['round_robin', 'pool'], true))
            ->values();

        if ($matches->isEmpty()) {
            return [];
        }

        $groups = $matches->groupBy(fn (TournamentMatch $match): string => (string) ($match->pool ?? ''));

        return $groups->map(function (Collection $group, string $pool): array {
            $ids = $group
                ->flatMap(fn (TournamentMatch $match): array => array_filter([
                    $match->participant1_id,
                    $match->participant2_id,
                ]))
                ->map(fn ($id): string => (string) $id)
                ->unique()
                ->values();

            $participants = TournamentParticipant::query()
                ->with(['user', 'partner'])
                ->whereIn('id', $ids)
                ->orderByRaw('seed IS NULL, seed ASC')
                ->get()
                ->keyBy(fn (TournamentParticipant $entry): string => (string) $entry->id);

            $ids = $ids
                ->sortBy(fn (string $id): int => $participants[$id]->seed ?? PHP_INT_MAX)
                ->values()
                ->all();

            $results = $group
                ->filter(fn (TournamentMatch $match): bool => $match->winner_id !== null
                    && $match->participant1_id !== null
                    && $match->participant2_id !== null)
                ->map(function (TournamentMatch $match): array {
                    [$first, $second] = self::pointsFromScore($match->score);

                    return [
                        'a' => (string) $match->participant1_id,
                        'b' => (string) $match->participant2_id,
                        'points' => [$first, $second],
                        'winner' => (int) $match->winner_id === (int) $match->participant1_id ? 0 : 1,
                    ];
                })
                ->values()
                ->all();

            return [
                'pool' => $pool === '' ? null : $pool,
                'rows' => collect(self::standingsRows($ids, $results))
                    ->map(fn (array $row): array => $row + [
                        'entry' => self::entryRef($participants[$row['id']] ?? null),
                    ])
                    ->all(),
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function matchViews(Tournament $tournament): array
    {
        $knockoutRounds = (int) $tournament->matches
            ->where('stage', 'knockout')
            ->max('round_number');

        $matches = $tournament->matches->sort(function (TournamentMatch $a, TournamentMatch $b): int {
            $rank = ['pool' => 0, 'round_robin' => 1, 'knockout' => 2];

            return ($rank[$a->stage] ?? 3) <=> ($rank[$b->stage] ?? 3)
                ?: strcmp((string) ($a->pool ?? ''), (string) ($b->pool ?? ''))
                ?: ((int) $a->round_number <=> (int) $b->round_number)
                ?: ((int) $a->match_number <=> (int) $b->match_number);
        })->values();

        return $matches->map(function (TournamentMatch $match) use ($knockoutRounds): array {
            $one = $match->participant1;
            $two = $match->participant2;
            $linked = $match->game;
            $hasMatch = ! $match->bye && $one !== null && $two !== null && $linked !== null;
            $winnerSide = $linked?->winner_side === null ? null : (int) $linked->winner_side;

            return [
                'id' => (string) $match->id,
                'stage' => in_array($match->stage, ['pool', 'round_robin', 'knockout'], true) ? $match->stage : 'knockout',
                'round' => (int) $match->round_number,
                'round_name' => self::roundName((int) $match->round_number, $knockoutRounds, $match->stage),
                'pool' => $match->pool,
                'slot' => (int) $match->match_number,
                'entries' => [self::entryRef($one), self::entryRef($two)],
                'bye' => (bool) $match->bye,
                'winner_entry_id' => $match->winner_id === null ? null : (string) $match->winner_id,
                'match' => $hasMatch ? [
                    'id' => (string) $linked->id,
                    'status' => MatchView::status($linked->status),
                    'score' => (string) ($linked->score ?? ''),
                    'winner' => $winnerSide,
                    'court' => $linked->court?->name ?? $match->court?->name,
                ] : null,
            ];
        })->all();
    }

    /**
     * @return array{type: string, area: array{scope: string, id: string, name: string}, ranked: list<array<string, mixed>>, provisional: list<array<string, mixed>>, me: array<string, mixed>|null, minimum_matches: int, my_areas: list<array{scope: string, id: string, name: string}>}
     */
    public static function leaderboard(string $type, string $scope, ?string $areaId, ?int $viewerId): array
    {
        $myAreas = $viewerId === null ? [] : self::homeAreas($viewerId);
        $homeArea = collect($myAreas)->firstWhere('scope', $scope);
        $areaId ??= $homeArea['id'] ?? ($scope === 'country' ? 'ph' : null);
        $area = $areaId === null ? null : self::area($scope, $areaId);

        $rows = PlayerProfile::query()
            ->with(['user.statistics'])
            ->whereNotNull('barangay_id')
            ->get()
            ->filter(function (PlayerProfile $profile) use ($scope, $areaId, $viewerId): bool {
                if ($profile->user === null || $profile->user->statistics === null) {
                    return false;
                }

                if ($profile->visibility === 'private' && (int) $profile->user_id !== $viewerId) {
                    return false;
                }

                $chain = GeoLocation::chain($profile->barangay_id);

                return $chain !== null && (string) $chain[$scope]->id === (string) $areaId;
            })
            ->map(function (PlayerProfile $profile) use ($scope, $type): array {
                $stat = $profile->user->statistics;
                $chain = GeoLocation::chain($profile->barangay_id);
                $rating = $type === 'singles'
                    ? ($stat->singles_rating ?? $stat->current_rating)
                    : ($stat->doubles_rating ?? $stat->current_rating);

                return [
                    'rank' => null,
                    'player' => [
                        'id' => (string) $profile->user_id,
                        'name' => (string) $profile->user->name,
                        'city' => $chain === null ? null : $chain['city']->name,
                        'avatar_url' => $profile->user->avatar,
                        'avatar_style' => $profile->avatar_style,
                    ],
                    'rating' => (int) round((float) $rating),
                    'games' => (int) $stat->matches_played,
                    'wins' => (int) $stat->matches_won,
                    'losses' => (int) $stat->matches_lost,
                    'provisional' => true,
                    'movement' => null,
                ];
            })
            ->filter(fn (array $row): bool => $row['games'] > 0)
            ->sort(fn (array $a, array $b): int => ($b['rating'] <=> $a['rating'])
                ?: ($b['games'] <=> $a['games'])
                ?: strcmp($a['player']['name'], $b['player']['name']))
            ->values();

        $minimum = RatingRules::current()['provisional_matches'];
        $ranked = $rows->where('games', '>=', $minimum)->values();
        $provisional = $rows->where('games', '<', $minimum)->values();

        $ranked = $ranked->map(function (array $row, int $index): array {
            $row['rank'] = $index + 1;
            $row['provisional'] = false;

            return $row;
        });

        $me = $viewerId === null
            ? null
            : ($ranked->first(fn (array $row): bool => (int) $row['player']['id'] === $viewerId)
                ?? $provisional->first(fn (array $row): bool => (int) $row['player']['id'] === $viewerId));

        return [
            'type' => $type,
            'area' => $area,
            'ranked' => $ranked->values()->all(),
            'provisional' => $provisional->values()->all(),
            'me' => $me,
            'minimum_matches' => RatingRules::current()['provisional_matches'],
            'my_areas' => $myAreas,
        ];
    }

    /**
     * Areas at one level that have at least one player with a rating.
     *
     * @return list<array{scope: string, id: string, name: string}>
     */
    public static function areas(string $scope): array
    {
        $ids = PlayerProfile::query()
            ->with('user.statistics')
            ->whereNotNull('barangay_id')
            ->get()
            ->filter(fn (PlayerProfile $profile): bool => $profile->user?->statistics !== null
                && (int) $profile->user->statistics->matches_played > 0)
            ->map(function (PlayerProfile $profile) use ($scope): ?string {
                $chain = GeoLocation::chain($profile->barangay_id);

                return $chain === null ? null : (string) $chain[$scope]->id;
            })
            ->filter()
            ->unique()
            ->values();

        return GeoLocation::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get()
            ->map(fn (GeoLocation $node): ?array => self::areaName($scope, (string) $node->id))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array{scope: string, id: string, name: string}>
     */
    public static function homeAreas(int $userId): array
    {
        $profile = PlayerProfile::query()->where('user_id', $userId)->first();
        $chain = GeoLocation::chain($profile?->barangay_id);

        if ($chain === null) {
            return [];
        }

        return [
            ['scope' => 'barangay', 'id' => (string) $chain['barangay']->id, 'name' => $chain['barangay']->name.', '.$chain['city']->name],
            ['scope' => 'city', 'id' => (string) $chain['city']->id, 'name' => $chain['city']->name.', '.$chain['province']->name],
            ['scope' => 'province', 'id' => (string) $chain['province']->id, 'name' => (string) $chain['province']->name],
            ['scope' => 'country', 'id' => (string) $chain['country']->id, 'name' => (string) $chain['country']->name],
        ];
    }

    /**
     * @return array{scope: string, id: string, name: string}|null
     */
    public static function area(string $scope, string $id): ?array
    {
        return self::areaName($scope, $id);
    }

    /**
     * @return array{scope: string, id: string, name: string}|null
     */
    private static function areaName(string $scope, string $id): ?array
    {
        $node = GeoLocation::query()->find($id);

        if ($node === null || $node->level !== $scope) {
            return null;
        }

        $parent = $node->parent_id === null ? null : GeoLocation::query()->find($node->parent_id);
        $name = match ($scope) {
            'barangay', 'city' => $parent === null ? $node->name : $node->name.', '.$parent->name,
            default => (string) $node->name,
        };

        return ['scope' => $scope, 'id' => (string) $node->id, 'name' => $name];
    }

    /**
     * @param  list<string>  $ids
     * @param  list<array{a: string, b: string, points: array{0: int, 1: int}, winner: int}>  $results
     * @return list<array<string, mixed>>
     */
    private static function standingsRows(array $ids, array $results): array
    {
        $rows = [];

        foreach ($ids as $id) {
            $rows[$id] = [
                'id' => $id,
                'played' => 0,
                'wins' => 0,
                'losses' => 0,
                'points_for' => 0,
                'points_against' => 0,
                'diff' => 0,
            ];
        }

        foreach ($results as $result) {
            if (! isset($rows[$result['a']], $rows[$result['b']])) {
                continue;
            }

            $a = &$rows[$result['a']];
            $b = &$rows[$result['b']];
            $a['played']++;
            $b['played']++;
            $a['points_for'] += $result['points'][0];
            $a['points_against'] += $result['points'][1];
            $b['points_for'] += $result['points'][1];
            $b['points_against'] += $result['points'][0];

            if ($result['winner'] === 0) {
                $a['wins']++;
                $b['losses']++;
            } else {
                $b['wins']++;
                $a['losses']++;
            }

            unset($a, $b);
        }

        foreach ($rows as &$row) {
            $row['diff'] = $row['points_for'] - $row['points_against'];
        }
        unset($row);

        $headToHead = function (string $x, string $y) use ($results): int {
            foreach ($results as $result) {
                if (($result['a'] === $x && $result['b'] === $y) || ($result['a'] === $y && $result['b'] === $x)) {
                    $xWon = ($result['a'] === $x && $result['winner'] === 0)
                        || ($result['b'] === $x && $result['winner'] === 1);

                    return $xWon ? -1 : 1;
                }
            }

            return 0;
        };

        $order = array_flip($ids);
        $list = array_values($rows);
        usort($list, fn (array $x, array $y): int => ($y['wins'] <=> $x['wins'])
            ?: ($y['diff'] <=> $x['diff'])
            ?: ($y['points_for'] <=> $x['points_for'])
            ?: $headToHead($x['id'], $y['id'])
            ?: (($order[$x['id']] ?? 0) <=> ($order[$y['id']] ?? 0)));

        return $list;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function pointsFromScore(?string $score): array
    {
        $points = [0, 0];

        if ($score === null || trim($score) === '') {
            return $points;
        }

        foreach (explode(',', $score) as $game) {
            if (preg_match('/(\d+)\s*-\s*(\d+)/', $game, $matches) === 1) {
                $points[0] += (int) $matches[1];
                $points[1] += (int) $matches[2];
            }
        }

        return $points;
    }

    private static function roundName(int $round, int $rounds, string $stage): string
    {
        if ($stage !== 'knockout') {
            return 'Round '.$round;
        }

        return match (true) {
            $round === $rounds => 'Final',
            $round === $rounds - 1 => 'Semifinals',
            $round === $rounds - 2 => 'Quarterfinals',
            default => 'Round '.$round,
        };
    }

    private static function matchStatus(mixed $status): string
    {
        return match ((string) $status) {
            'in_progress' => 'in_progress',
            'completed', 'forfeit' => 'completed',
            'voided', 'cancelled' => 'voided',
            default => 'scheduled',
        };
    }

    private static function iso(mixed $value): string
    {
        return $value === null ? CarbonImmutable::now()->toIso8601String() : CarbonImmutable::instance($value)->toIso8601String();
    }
}
