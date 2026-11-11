<?php

namespace App\Support\Compat;

use App\Models\Court;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use Illuminate\Support\Collection;

/**
 * Builds the bracket/draw rows for a tournament using the same seed order and
 * round-robin rules as packages/api/src/bracket.ts.
 */
class TournamentDraw
{
    private static int $courtCursor = 0;

    private static bool $drawing = false;

    /**
     * @param  Collection<int, TournamentParticipant>  $entries
     */
    public static function build(Tournament $tournament, Collection $entries): void
    {
        self::$courtCursor = 0;
        $seeded = $entries->values();
        self::$drawing = true;

        try {
            match (CompetitionView::format($tournament->format)) {
                'round_robin' => self::roundRobin($tournament, $seeded, 'round_robin', null),
                'pool_knockout' => self::pools($tournament, $seeded),
                default => self::knockout($tournament, $seeded),
            };
        } finally {
            self::$drawing = false;
        }
    }

    /**
     * @param  Collection<int, TournamentParticipant>  $seeded
     */
    private static function knockout(Tournament $tournament, Collection $seeded): void
    {
        $size = self::bracketSize($seeded->count());
        $rounds = (int) round(log($size, 2));
        $order = self::seedOrder($size);
        /** @var array<int, array<int, TournamentMatch>> $roundsMap */
        $roundsMap = [];

        for ($round = 1; $round <= $rounds; $round++) {
            $count = intdiv($size, 2 ** $round);
            for ($slot = 0; $slot < $count; $slot++) {
                $roundsMap[$round][$slot] = self::create($tournament, 'knockout', null, $round, $slot + 1);
            }
        }

        foreach ($roundsMap as $round => $matches) {
            if ($round === $rounds) {
                continue;
            }

            foreach ($matches as $slot => $match) {
                $next = $roundsMap[$round + 1][intdiv($slot, 2)];
                $match->next_match_id = $next->id;
                $match->next_side = $slot % 2;
                $match->save();
            }
        }

        foreach ($roundsMap[1] as $slot => $match) {
            $first = $order[$slot * 2] ?? null;
            $second = $order[$slot * 2 + 1] ?? null;

            $match->participant1_id = $first !== null && $first <= $seeded->count()
                ? $seeded[$first - 1]->id
                : null;
            $match->participant2_id = $second !== null && $second <= $seeded->count()
                ? $seeded[$second - 1]->id
                : null;
            self::fill($match);
        }

        // A bye moves its player forward immediately, including byes created
        // by another bye in the next round.
        for ($round = 1; $round <= $rounds; $round++) {
            foreach ($roundsMap[$round] as $match) {
                if ($match->winner_id !== null) {
                    self::advance($match);
                }
            }
        }
    }

    /**
     * @param  Collection<int, TournamentParticipant>  $seeded
     */
    private static function pools(Tournament $tournament, Collection $seeded): void
    {
        $pools = max(2, min(4, (int) ($tournament->pools ?? 2)));

        foreach (self::snakePools($seeded->count(), $pools) as $index => $pool) {
            $entries = collect($pool)->map(fn (int $entry): TournamentParticipant => $seeded[$entry]);
            self::roundRobin($tournament, $entries, 'pool', chr(65 + $index));
        }
    }

    /**
     * @param  Collection<int, TournamentParticipant>  $seeded
     */
    private static function roundRobin(
        Tournament $tournament,
        Collection $seeded,
        string $stage,
        ?string $pool,
    ): void {
        foreach (self::roundRobinPairs($seeded->count()) as $round => $pairs) {
            foreach ($pairs as $slot => [$first, $second]) {
                $match = self::create($tournament, $stage, $pool, $round + 1, $slot + 1);
                $match->participant1_id = $seeded[$first]->id;
                $match->participant2_id = $seeded[$second]->id;
                self::fill($match);
            }
        }
    }

    private static function create(
        Tournament $tournament,
        string $stage,
        ?string $pool,
        int $round,
        int $slot,
    ): TournamentMatch {
        return TournamentMatch::query()->create([
            'tournament_id' => $tournament->id,
            'stage' => $stage,
            'pool' => $pool,
            'round_number' => $round,
            'match_number' => $slot,
            'bye' => false,
            'status' => 'scheduled',
            'scheduled_at' => $tournament->starts_at,
        ]);
    }

    private static function fill(TournamentMatch $match): void
    {
        $hasBoth = $match->participant1_id !== null && $match->participant2_id !== null;

        if ($hasBoth) {
            if ($match->court_id === null) {
                $match->court_id = self::nextCourt($match->tournament_id);
            }

            // Both sides are known, so a slot that was waiting (or briefly
            // looked like a bye) is a real match now.
            $match->bye = false;

            if ($match->winner_id === null && $match->status !== 'bye') {
                $match->status = 'scheduled';
            }

            $match->save();
            self::ensureMatch($match);

            return;
        }

        $one = $match->participant1_id ?? $match->participant2_id;
        $openingRound = $match->stage === 'knockout' && (int) $match->round_number === 1;

        // Only the opening knockout round can have a bye. A later slot with
        // one known side is simply waiting for its other feeder match.
        if ($openingRound && $one !== null && $match->winner_id === null) {
            $match->bye = true;
            $match->winner_id = $one;
            $match->status = 'bye';
            $match->save();
        }
    }

    private static function advance(TournamentMatch $match): void
    {
        if ($match->next_match_id === null || $match->winner_id === null) {
            return;
        }

        $next = TournamentMatch::query()->find($match->next_match_id);

        if ($next === null) {
            return;
        }

        if ($match->next_side === 0) {
            $next->participant1_id = $match->winner_id;
        } else {
            $next->participant2_id = $match->winner_id;
        }

        $next->save();
        self::fill($next);
    }

    /**
     * Creates the real match for a draw slot once both entries are known.
     * Tournament matches are ordinary matches (category "tournament").
     */
    public static function ensureMatch(TournamentMatch $slot): ?GameMatch
    {
        if ($slot->match_id !== null || $slot->winner_id !== null
            || $slot->participant1_id === null || $slot->participant2_id === null) {
            return null;
        }

        $tournament = Tournament::query()
            ->with(['participants.user', 'participants.partner'])
            ->find($slot->tournament_id);

        if ($tournament === null) {
            return null;
        }

        $one = $tournament->participants->firstWhere('id', $slot->participant1_id);
        $two = $tournament->participants->firstWhere('id', $slot->participant2_id);

        if ($one === null || $two === null) {
            return null;
        }

        $teamA = array_values(array_filter([$one->user_id, $one->partner_id]));
        $teamB = array_values(array_filter([$two->user_id, $two->partner_id]));
        $size = CompetitionView::matchType($tournament->match_type) === 'singles' ? 1 : 2;

        if (count($teamA) !== $size || count($teamB) !== $size) {
            return null;
        }

        $game = GameMatch::query()->create([
            'match_type' => CompetitionView::matchType($tournament->match_type),
            'category' => 'tournament',
            'format' => CompetitionView::game($tournament->game),
            'status' => 'scheduled',
            'venue_id' => $tournament->venue_id ?? $tournament->tenant_id,
            'court_id' => $slot->court_id,
            'tournament_id' => $tournament->id,
            'first_serving' => 0,
            'scorer_id' => $tournament->organizer_id,
            'created_by' => $tournament->organizer_id,
            'score' => '',
        ]);

        foreach ([$teamA, $teamB] as $side => $players) {
            foreach ($players as $position => $playerId) {
                MatchPlayer::query()->create([
                    'match_id' => $game->id,
                    'user_id' => $playerId,
                    'side' => $side,
                    'position' => $position,
                ]);
            }
        }

        $slot->match_id = $game->id;
        $slot->save();

        if (! self::$drawing && $tournament->status === 'in_progress') {
            $court = $slot->court_id === null ? null : Court::query()->find($slot->court_id);
            $pairing = CompetitionView::entryName($one).' vs '.CompetitionView::entryName($two)
                .($court === null ? '' : " on {$court->name}").'.';

            foreach (array_filter([$one->user_id, $one->partner_id, $two->user_id, $two->partner_id]) as $playerId) {
                NotificationService::send(
                    (int) $playerId,
                    'tournaments',
                    'tournament_match_ready',
                    "{$tournament->name}: your next match",
                    $pairing,
                    "/matches/{$game->id}",
                );
            }
        }

        return $game;
    }

    /**
     * After an official tournament match: move the winner on, finish a group,
     * or crown the champion.
     */
    public static function applyResult(GameMatch $game): void
    {
        $slot = TournamentMatch::query()->where('match_id', $game->id)->first();

        if ($slot === null || $slot->winner_id !== null) {
            return;
        }

        $tournament = Tournament::query()->find($slot->tournament_id);

        if ($tournament === null) {
            return;
        }

        $winnerId = (int) $game->winner_side === 0 ? $slot->participant1_id : $slot->participant2_id;

        if ($winnerId === null) {
            return;
        }

        $slot->winner_id = $winnerId;
        $slot->score = (string) ($game->score ?? '');
        $slot->status = 'completed';
        $slot->save();

        if ($slot->stage === 'knockout') {
            $rounds = (int) TournamentMatch::query()
                ->where('tournament_id', $tournament->id)
                ->where('stage', 'knockout')
                ->max('round_number');

            if ((int) $slot->round_number >= $rounds) {
                $tournament->update([
                    'champion_participant_id' => $winnerId,
                    'status' => 'completed',
                ]);
            } else {
                self::advanceWinner($slot, $winnerId);
            }

            return;
        }

        self::finishGroup($tournament, (string) $slot->stage);
    }

    public static function replaceVoided(GameMatch $game): void
    {
        $slot = TournamentMatch::query()->where('match_id', $game->id)->first();

        if ($slot === null || $slot->winner_id !== null) {
            return;
        }

        $slot->match_id = null;
        $slot->score = null;
        $slot->status = 'scheduled';
        $slot->save();
        self::ensureMatch($slot);
    }

    private static function advanceWinner(TournamentMatch $slot, int $winnerId): void
    {
        if ($slot->next_match_id === null) {
            return;
        }

        $next = TournamentMatch::query()->find($slot->next_match_id);

        if ($next === null) {
            return;
        }

        if ((int) $slot->next_side === 0) {
            $next->participant1_id = $winnerId;
        } else {
            $next->participant2_id = $winnerId;
        }

        $next->save();
        self::fill($next);
    }

    private static function finishGroup(Tournament $tournament, string $stage): void
    {
        $slots = TournamentMatch::query()
            ->where('tournament_id', $tournament->id)
            ->where('stage', $stage)
            ->get();

        if ($slots->isEmpty() || $slots->contains(fn (TournamentMatch $slot): bool => $slot->winner_id === null)) {
            return;
        }

        $tournament->load('matches');
        $tables = CompetitionView::standings($tournament);

        if ($stage === 'round_robin') {
            $champion = $tables[0]['rows'][0]['entry']['id'] ?? null;

            if ($champion !== null) {
                $tournament->update([
                    'champion_participant_id' => (int) $champion,
                    'status' => 'completed',
                ]);
            }

            return;
        }

        $alreadyDrawn = TournamentMatch::query()
            ->where('tournament_id', $tournament->id)
            ->where('stage', 'knockout')
            ->exists();

        if ($alreadyDrawn) {
            return;
        }

        $ids = [];

        foreach ($tables as $table) {
            foreach (array_slice($table['rows'], 0, 2) as $row) {
                $ids[] = (int) $row['entry']['id'];
            }
        }

        if (count($ids) < 2) {
            return;
        }

        $entries = TournamentParticipant::query()
            ->with(['user', 'partner'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
        $seeded = collect($ids)
            ->map(fn (int $id): ?TournamentParticipant => $entries->get($id))
            ->filter()
            ->values();

        self::$courtCursor = 0;
        self::knockout($tournament, $seeded);
    }

    public static function nextCourt(int $tournamentId): ?int
    {
        $tournament = Tournament::query()->find($tournamentId);
        $ids = is_array($tournament?->court_ids) ? array_values($tournament->court_ids) : [];

        if ($ids === []) {
            $ids = Court::query()
                ->where('tenant_id', $tournament?->venue_id ?? $tournament?->tenant_id)
                ->pluck('id')
                ->all();
        }

        if ($ids === []) {
            return null;
        }

        $id = $ids[self::$courtCursor % count($ids)];
        self::$courtCursor++;

        return (int) $id;
    }

    private static function bracketSize(int $entries): int
    {
        return 2 ** max(1, (int) ceil(log(max($entries, 2), 2)));
    }

    /**
     * @return list<int>
     */
    private static function seedOrder(int $size): array
    {
        $order = [1];

        while (count($order) < $size) {
            $next = count($order) * 2;
            $expanded = [];

            foreach ($order as $seed) {
                $expanded[] = $seed;
                $expanded[] = $next + 1 - $seed;
            }

            $order = $expanded;
        }

        return $order;
    }

    /**
     * @return list<list<array{0: int, 1: int}>>
     */
    private static function roundRobinPairs(int $count): array
    {
        $ids = range(0, $count - 1);

        if ($count % 2 === 1) {
            $ids[] = -1;
        }

        $n = count($ids);
        $rounds = [];

        for ($round = 0; $round < $n - 1; $round++) {
            $pairs = [];

            for ($i = 0; $i < intdiv($n, 2); $i++) {
                $a = $ids[$i];
                $b = $ids[$n - 1 - $i];

                if ($a !== -1 && $b !== -1) {
                    $pairs[] = $a < $b ? [$a, $b] : [$b, $a];
                }
            }

            $rounds[] = $pairs;
            $last = array_pop($ids);
            array_splice($ids, 1, 0, [$last]);
        }

        return $rounds;
    }

    /**
     * @return list<list<int>>
     */
    private static function snakePools(int $count, int $pools): array
    {
        $out = array_fill(0, $pools, []);

        for ($i = 0; $i < $count; $i++) {
            $lap = intdiv($i, $pools);
            $position = $i % $pools;
            $out[$lap % 2 === 0 ? $position : $pools - 1 - $position][] = $i;
        }

        return $out;
    }
}
