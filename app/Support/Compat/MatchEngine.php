<?php

namespace App\Support\Compat;

use App\Models\GameMatch;
use App\Models\MatchConfirmation;
use App\Models\MatchEvent;
use App\Models\MatchPlayer;
use App\Models\MatchRatingChange;
use App\Models\PlayerStatistic;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Match lifecycle: immutable score events, result confirmation, ELO changes and
 * the tournament draw advancing when a result becomes official.
 */
class MatchEngine
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function create(array $data, User $user): GameMatch
    {
        return DB::transaction(function () use ($data, $user): GameMatch {
            $match = GameMatch::query()->create([
                'match_type' => $data['match_type'],
                'category' => $data['category'],
                'format' => $data['format'],
                'status' => 'scheduled',
                'venue_id' => $data['venue_id'] ?? null,
                'court_id' => $data['court_id'] ?? null,
                'session_id' => $data['session_id'] ?? null,
                'tournament_id' => null,
                'first_serving' => (int) ($data['first_serving'] ?? 0),
                'scorer_id' => (int) ($data['scorer_id'] ?? $user->id),
                'created_by' => (int) $user->id,
                'score' => '',
            ]);

            foreach ([0, 1] as $side) {
                foreach (array_values($data['team_'.$side] ?? []) as $position => $playerId) {
                    MatchPlayer::query()->create([
                        'match_id' => $match->id,
                        'user_id' => (int) $playerId,
                        'side' => $side,
                        'position' => $position,
                    ]);
                }
            }

            return $match;
        });
    }

    /**
     * @return array{target: int, win_by: int, best_of: int, scoring: string}
     */
    public static function format(GameMatch $match): array
    {
        $stored = is_array($match->format) ? $match->format : [];
        $target = (int) ($stored['target'] ?? 11);
        $winBy = (int) ($stored['win_by'] ?? 2);
        $bestOf = (int) ($stored['best_of'] ?? 1);

        return [
            'target' => in_array($target, [11, 15, 21], true) ? $target : 11,
            'win_by' => in_array($winBy, [1, 2], true) ? $winBy : 2,
            'best_of' => in_array($bestOf, [1, 3], true) ? $bestOf : 1,
            'scoring' => ($stored['scoring'] ?? 'side_out') === 'rally' ? 'rally' : 'side_out',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function state(GameMatch $match): array
    {
        $match->loadMissing('events');

        return MatchScoring::replay(
            self::inputs($match),
            self::format($match),
            (string) $match->match_type,
            (int) $match->first_serving,
        );
    }

    /**
     * @return list<array{type: string, winner: int|null}>
     */
    public static function inputs(GameMatch $match): array
    {
        return $match->events
            ->map(fn (MatchEvent $event): array => [
                'type' => (string) $event->type,
                'winner' => $event->winner === null ? null : (int) $event->winner,
            ])
            ->values()
            ->all();
    }

    public static function scoreText(GameMatch $match): string
    {
        $state = self::state($match);

        return $state['rallies'] > 0 ? MatchScoring::finalScore($state) : '';
    }

    public static function recordEvent(
        GameMatch $match,
        string $uuid,
        string $type,
        ?int $winner,
        int $scorerId,
    ): MatchEvent {
        return DB::transaction(function () use ($match, $uuid, $type, $winner, $scorerId): MatchEvent {
            $event = MatchEvent::query()->create([
                'match_id' => $match->id,
                'seq' => ((int) MatchEvent::query()->where('match_id', $match->id)->max('seq')) + 1,
                'event_uuid' => $uuid,
                'type' => $type,
                'winner' => $winner,
                'scorer_id' => $scorerId,
            ]);

            $match->load('events');
            $state = self::state($match);
            $match->score = $state['rallies'] > 0 ? MatchScoring::finalScore($state) : '';
            $match->winner_side = $state['winner'];
            $match->save();

            return $event;
        });
    }

    public static function submit(GameMatch $match, int $userId): bool
    {
        $state = self::state($match);

        if ($match->status !== 'in_progress' || $state['winner'] === null) {
            return false;
        }

        $match->submitted_by = $userId;
        $match->submitted_at = now();
        $match->ended_at = now();
        $match->score = MatchScoring::finalScore($state);
        $match->winner_side = $state['winner'];

        $onTeam = $match->players()->where('user_id', $userId)->exists();

        if ($match->category === 'casual' || ! $onTeam) {
            $match->save();

            return self::complete($match, $userId);
        }

        $match->status = 'awaiting_confirmation';
        $match->save();

        $match->loadMissing('players.user');
        $side = MatchView::awaitingSide($match);
        $submitter = User::query()->find($userId);
        $name = NotificationService::firstName((string) ($submitter?->name ?? 'A player'));
        $score = (string) ($match->score ?: self::scoreText($match));

        if ($side !== null) {
            foreach ($match->players->where('side', $side) as $player) {
                NotificationService::send(
                    (int) $player->user_id,
                    'matches',
                    'result_to_confirm',
                    'Confirm your result',
                    "{$name} submitted {$score}. Confirm it, or dispute it if it's wrong. It's official in 48 hours otherwise.",
                    "/matches/{$match->id}",
                );
            }
        }

        return true;
    }

    public static function complete(GameMatch $match, ?int $by, bool $notify = true): bool
    {
        $completed = DB::transaction(function () use ($match, $by): bool {
            $row = GameMatch::query()
                ->with(['players.user', 'events'])
                ->lockForUpdate()
                ->find($match->id);

            if ($row === null || in_array($row->status, ['completed', 'voided'], true)) {
                return false;
            }

            $state = self::state($row);

            if ($state['winner'] === null) {
                return false;
            }

            $row->status = 'completed';
            $row->score = MatchScoring::finalScore($state);
            $row->winner_side = $state['winner'];
            $row->ended_at ??= now();
            $row->verified_at = now();
            $row->verified_by = $by;
            $row->save();

            if ($row->category !== 'casual') {
                self::applyRatings($row);
            }

            self::applyTotals($row);

            if ($row->tournament_id !== null) {
                TournamentDraw::applyResult($row);
            }

            return true;
        });

        $match->refresh();

        if ($completed && $notify) {
            self::notifyOfficial($match);
        }

        return $completed;
    }

    private static function notifyOfficial(GameMatch $match): void
    {
        if ($match->category === 'casual') {
            return;
        }

        $match->loadMissing(['players.user', 'ratingChanges']);
        $score = (string) ($match->score ?? '');
        $base = $score === '' ? '' : $score.'.';

        foreach ($match->players as $player) {
            $change = $match->ratingChanges->first(
                fn (MatchRatingChange $row): bool => (int) $row->player_id === (int) $player->user_id
                    && $row->kind === 'match',
            );
            $won = (int) $player->side === (int) $match->winner_side;
            $body = $base;

            if ($change !== null) {
                $body .= ' Your '.$match->match_type.' rating '
                    .((int) $change->change >= 0 ? 'rose ' : 'fell ')
                    .abs((int) $change->change).' to '.(int) $change->new_rating.'.';
            }

            NotificationService::send(
                (int) $player->user_id,
                'matches',
                'result_official',
                $won ? "You won: it's official" : 'Result is official',
                trim($body),
                "/matches/{$match->id}",
            );
        }
    }

    /**
     * Undo a completed, non-tournament result with compensating ledger entries.
     */
    public static function reverse(GameMatch $match, int $by, string $reason): int
    {
        return DB::transaction(function () use ($match, $by, $reason): int {
            $row = GameMatch::query()
                ->with(['players'])
                ->lockForUpdate()
                ->find($match->id);

            if ($row === null || $row->status !== 'completed') {
                return 0;
            }

            $original = MatchRatingChange::query()
                ->where('match_id', $row->id)
                ->where('kind', 'match')
                ->get();

            foreach ($original as $change) {
                $stat = self::statFor((int) $change->player_id);
                $field = $change->type === 'singles' ? 'singles_rating' : 'doubles_rating';
                $current = (int) round((float) ($stat->{$field} ?? $stat->current_rating ?? RatingRules::current()['initial']));
                $new = $current - (int) $change->change;

                MatchRatingChange::query()->create([
                    'match_id' => $row->id,
                    'player_id' => $change->player_id,
                    'type' => $change->type,
                    'kind' => 'reversal',
                    'old_rating' => $current,
                    'change' => -(int) $change->change,
                    'new_rating' => $new,
                    'version' => MatchScoring::RATING_VERSION.'-reversal',
                ]);

                $stat->{$field} = $new;
                $stat->current_rating = $new;
                self::decrementFormat($stat, $change->type, (int) $change->change > 0);
                $stat->save();
            }

            $winner = (int) ($row->winner_side ?? 0);
            $matchType = (string) $row->match_type;

            foreach ($row->players as $player) {
                $stat = self::statFor((int) $player->user_id);
                $stat->matches_played = max(0, (int) $stat->matches_played - 1);

                if ((int) $player->side === $winner) {
                    $stat->matches_won = max(0, (int) $stat->matches_won - 1);
                } else {
                    $stat->matches_lost = max(0, (int) $stat->matches_lost - 1);
                }

                // A rated reversal already removed the format's game; only a
                // casual result has no rating ledger entry to do it.
                if ($row->category === 'casual') {
                    self::decrementFormat($stat, $matchType, (int) $player->side === $winner);
                }

                $stat->save();
            }

            $row->status = 'voided';
            $row->void_reason = $reason;
            $row->ended_at ??= now();
            $row->save();

            return $original->count();
        });
    }

    public static function settleDue(): void
    {
        $due = GameMatch::query()
            ->where('status', 'awaiting_confirmation')
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '<=', now()->subHours(48))
            ->orderBy('id')
            ->get();

        foreach ($due as $match) {
            self::complete($match, null);
        }
    }

    public static function canManage(GameMatch $match, ?int $viewerId): bool
    {
        if ($viewerId === null) {
            return false;
        }

        $user = User::query()->find($viewerId);

        if ($user === null) {
            return false;
        }

        if ($user->isSystemAdmin() || (int) $match->created_by === $viewerId) {
            return true;
        }

        if ($match->tournament !== null && CompetitionView::canManage($match->tournament, $viewerId)) {
            return true;
        }

        if ($match->session !== null && Contract::canManageOpenPlay($match->session, $viewerId)) {
            return true;
        }

        return $match->venue_id !== null && CompetitionView::canManageVenue((int) $match->venue_id, $viewerId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function stats(int $userId, int $viewerId): ?array
    {
        $profile = \App\Models\PlayerProfile::query()->with('user')->where('user_id', $userId)->first();

        if ($profile === null || $profile->user === null) {
            return null;
        }

        $rules = RatingRules::current();
        $stat = PlayerStatistic::query()->firstOrCreate(
            ['user_id' => $userId],
            [
                'current_rating' => $rules['initial'],
                'singles_rating' => $rules['initial'],
                'doubles_rating' => $rules['initial'],
            ],
        );

        $finished = GameMatch::query()
            ->with(['players.user', 'venue', 'court', 'session', 'scorer', 'creator'])
            ->where('status', 'completed')
            ->whereHas('players', fn ($query) => $query->where('user_id', $userId))
            ->orderBy('ended_at')
            ->orderBy('id')
            ->get();

        $won = fn (GameMatch $match): bool => $match->winner_side !== null
            && $match->players
                ->where('side', (int) $match->winner_side)
                ->contains(fn (MatchPlayer $player): bool => (int) $player->user_id === $userId);

        $chain = \App\Models\GeoLocation::chain($profile->barangay_id);
        $ratingSummary = function (string $type) use ($stat, $rules): array {
            $games = (int) $stat->{$type.'_matches'};
            $rating = (int) round((float) ($stat->{$type.'_rating'} ?? $stat->current_rating ?? $rules['initial']));

            return [
                'type' => $type,
                'rating' => $rating,
                'provisional' => $games < $rules['provisional_matches'],
                'games' => $games,
                'wins' => (int) $stat->{$type.'_wins'},
                'losses' => (int) $stat->{$type.'_losses'},
                'matches_to_rank' => max($rules['provisional_matches'] - $games, 0),
            ];
        };
        $history = function (string $type) use ($userId): array {
            return MatchRatingChange::query()
                ->where('player_id', $userId)
                ->where('type', $type)
                ->whereIn('kind', ['match', 'reversal'])
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn (MatchRatingChange $row): array => [
                    'at' => $row->created_at->toIso8601String(),
                    'rating' => (int) $row->new_rating,
                ])
                ->all();
        };
        $ranks = [];

        foreach (['singles', 'doubles'] as $type) {
            foreach (CompetitionView::homeAreas($userId) as $area) {
                $board = CompetitionView::leaderboard($type, $area['scope'], $area['id'], $viewerId);
                $row = collect($board['ranked'])->firstWhere('player.id', (string) $userId);

                if ($row !== null) {
                    $ranks[] = [
                        'type' => $type,
                        'area' => $area,
                        'rank' => (int) $row['rank'],
                        'of' => count($board['ranked']),
                    ];
                }
            }
        }

        return [
            'player' => [
                'id' => (string) $userId,
                'name' => (string) ($profile->display_name ?: $profile->user->name),
                'home_city' => $chain === null ? null : $chain['city']->name,
                'skill' => (string) ($profile->skill_level ?: 'intermediate'),
                'avatar_url' => $profile->user->avatar,
                'avatar_style' => $profile->avatar_style,
            ],
            'ratings' => [
                'singles' => $ratingSummary('singles'),
                'doubles' => $ratingSummary('doubles'),
            ],
            'totals' => [
                'played' => (int) $stat->matches_played,
                'wins' => (int) $stat->matches_won,
                'losses' => (int) $stat->matches_lost,
                'casual' => $finished->where('category', 'casual')->count(),
            ],
            'form' => $finished->slice(-5)->map(fn (GameMatch $match): string => $won($match) ? 'W' : 'L')->values()->all(),
            'history' => [
                'singles' => $history('singles'),
                'doubles' => $history('doubles'),
            ],
            'recent' => $finished->reverse()->take(10)->map(fn (GameMatch $match): array => MatchView::summary($match))->values()->all(),
            'ranks' => $ranks,
        ];
    }

    private static function applyRatings(GameMatch $match): void
    {
        if (MatchRatingChange::query()->where('match_id', $match->id)->where('kind', 'match')->exists()) {
            return;
        }

        $type = (string) $match->match_type;

        if (! in_array($type, ['singles', 'doubles'], true)) {
            return;
        }

        $teams = [[], []];

        $rules = RatingRules::current();

        foreach ($match->players as $player) {
            $stat = self::statFor((int) $player->user_id);
            $rating = (int) round((float) ($stat->{$type.'_rating'} ?? $stat->current_rating ?? $rules['initial']));
            $teams[(int) $player->side][] = [
                'id' => (int) $player->user_id,
                'rating' => $rating,
                'games' => (int) $stat->{$type.'_matches'},
            ];
        }

        $changes = MatchScoring::ratingChanges($teams, (int) $match->winner_side, $rules);

        foreach ($changes as $change) {
            MatchRatingChange::query()->create([
                'match_id' => $match->id,
                'player_id' => $change['id'],
                'type' => $type,
                'kind' => 'match',
                'old_rating' => $change['old'],
                'change' => $change['change'],
                'new_rating' => $change['new'],
                'version' => MatchScoring::RATING_VERSION,
            ]);

            $stat = self::statFor($change['id']);
            $field = $type === 'singles' ? 'singles_rating' : 'doubles_rating';
            $stat->{$field} = $change['new'];
            $stat->current_rating = $change['new'];
            $stat->{$type.'_matches'} = (int) $stat->{$type.'_matches'} + 1;

            $player = $match->players->firstWhere('user_id', $change['id']);
            if ((int) $player?->side === (int) $match->winner_side) {
                $stat->{$type.'_wins'} = (int) $stat->{$type.'_wins'} + 1;
            } else {
                $stat->{$type.'_losses'} = (int) $stat->{$type.'_losses'} + 1;
            }

            $stat->save();
        }
    }

    private static function applyTotals(GameMatch $match): void
    {
        foreach ($match->players as $player) {
            $stat = self::statFor((int) $player->user_id);
            $stat->matches_played = (int) $stat->matches_played + 1;

            if ((int) $player->side === (int) $match->winner_side) {
                $stat->matches_won = (int) $stat->matches_won + 1;
            } else {
                $stat->matches_lost = (int) $stat->matches_lost + 1;
            }

            $stat->save();
        }
    }

    private static function statFor(int $userId): PlayerStatistic
    {
        $initial = RatingRules::current()['initial'];

        return PlayerStatistic::query()->firstOrCreate(
            ['user_id' => $userId],
            [
                'current_rating' => $initial,
                'singles_rating' => $initial,
                'doubles_rating' => $initial,
            ],
        );
    }

    private static function decrementFormat(PlayerStatistic $stat, string $type, bool $won): void
    {
        $type = in_array($type, ['singles', 'doubles'], true) ? $type : 'doubles';
        $stat->{$type.'_matches'} = max(0, (int) $stat->{$type.'_matches'} - 1);

        if ($won) {
            $stat->{$type.'_wins'} = max(0, (int) $stat->{$type.'_wins'} - 1);
        } else {
            $stat->{$type.'_losses'} = max(0, (int) $stat->{$type.'_losses'} - 1);
        }
    }

    public static function uuid(): string
    {
        return (string) Str::uuid();
    }

    public static function pendingTournamentSlot(GameMatch $match): ?TournamentMatch
    {
        return TournamentMatch::query()->where('match_id', $match->id)->first();
    }

    public static function confirm(GameMatch $match, int $userId): bool
    {
        $side = MatchView::awaitingSide($match);

        if ($match->status !== 'awaiting_confirmation' || $side === null) {
            return false;
        }

        $onSide = $match->players
            ->where('side', $side)
            ->contains(fn (MatchPlayer $player): bool => (int) $player->user_id === $userId);

        if (! $onSide) {
            return false;
        }

        MatchConfirmation::query()->firstOrCreate(
            ['match_id' => $match->id, 'player_id' => $userId],
            ['confirmed_at' => now()],
        );

        return self::complete($match, $userId);
    }
}
