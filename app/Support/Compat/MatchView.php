<?php

namespace App\Support\Compat;

use App\Models\GameMatch;
use App\Models\MatchConfirmation;
use App\Models\MatchEvent;
use App\Models\MatchPlayer;
use App\Models\MatchRatingChange;
use App\Models\TournamentMatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Contract shapes for matches and player statistics
 * (packages/api/src/match-types.ts).
 */
class MatchView
{
    public const AUTO_VERIFY_MS = 48 * 3_600_000;

    /**
     * @return array<string, mixed>
     */
    public static function summary(GameMatch $match): array
    {
        $match->loadMissing([
            'players.user',
            'venue',
            'court',
            'session',
            'scorer',
            'creator',
        ]);

        $teams = [[], []];

        foreach ($match->players as $player) {
            if ($player->user === null) {
                continue;
            }

            $teams[(int) $player->side][] = [
                'id' => (string) $player->user_id,
                'name' => (string) $player->user->name,
            ];
        }

        $status = self::status($match->status);
        $winner = in_array($status, ['scheduled', 'in_progress'], true)
            ? null
            : ($match->winner_side === null ? null : (int) $match->winner_side);

        return [
            'id' => (string) $match->id,
            'match_type' => $match->match_type === 'singles' ? 'singles' : 'doubles',
            'category' => in_array($match->category, ['casual', 'competitive', 'tournament'], true)
                ? $match->category
                : 'casual',
            'status' => $status,
            'format' => MatchEngine::format($match),
            'teams' => [$teams[0], $teams[1]],
            'score' => (string) ($match->score ?? ''),
            'winner' => $winner,
            'awaiting_side' => $status === 'awaiting_confirmation' ? self::awaitingSide($match) : null,
            'venue' => $match->venue === null ? null : [
                'id' => (string) $match->venue->id,
                'name' => (string) $match->venue->name,
            ],
            'court' => $match->court === null ? null : [
                'id' => (string) $match->court->id,
                'name' => (string) $match->court->name,
            ],
            'session' => $match->session === null ? null : [
                'id' => (string) $match->session->id,
                'title' => (string) $match->session->title,
            ],
            'scorer' => self::person($match->scorer) ?? ['id' => '0', 'name' => 'Scorer'],
            'created_by' => self::person($match->creator) ?? ['id' => '0', 'name' => 'System'],
            'created_at' => self::iso($match->created_at),
            'started_at' => $match->started_at?->toIso8601String(),
            'ended_at' => $match->ended_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(GameMatch $match, ?int $viewerId): array
    {
        $match->loadMissing([
            'players.user',
            'venue',
            'court',
            'session',
            'tournament',
            'scorer',
            'creator',
            'events.scorer',
            'confirmations.player',
            'ratingChanges.player',
            'submitter',
            'verifier',
            'disputer',
            'resolver',
        ]);

        $summary = self::summary($match);
        $state = MatchEngine::state($match);
        $side = self::awaitingSide($match);
        $onTeam = self::onTeam($match, $viewerId);
        $confirmed = $viewerId !== null && $match->confirmations
            ->contains(fn (MatchConfirmation $row): bool => (int) $row->player_id === $viewerId);
        $manage = MatchEngine::canManage($match, $viewerId);

        return $summary + [
            'first_serving' => (int) ($match->first_serving ?? 0),
            'state' => $state,
            'version' => $match->events->count(),
            'can_score' => $viewerId !== null
                && in_array($summary['status'], ['scheduled', 'in_progress'], true)
                && ((int) $match->scorer_id === $viewerId || $manage),
            'can_manage' => $manage,
            'can_confirm' => $summary['status'] === 'awaiting_confirmation'
                && $viewerId !== null
                && $side !== null
                && self::onSide($match, $viewerId, $side)
                && ! $confirmed,
            'can_dispute' => $summary['status'] === 'awaiting_confirmation' && $onTeam,
            'can_verify' => in_array($summary['status'], ['awaiting_confirmation', 'disputed'], true)
                && self::canVerify($match, $viewerId),
            'result' => self::result($match),
            'ratings' => $match->ratingChanges
                ->map(fn (MatchRatingChange $row): array => [
                    'player' => self::person($row->player) ?? ['id' => (string) $row->player_id, 'name' => 'Player'],
                    'old' => (int) $row->old_rating,
                    'change' => (int) $row->change,
                    'new' => (int) $row->new_rating,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function result(GameMatch $match): ?array
    {
        if ($match->submitted_at === null) {
            return null;
        }

        $match->loadMissing(['confirmations.player', 'submitter', 'verifier', 'disputer', 'resolver']);

        $resolution = null;

        if ($match->resolution_at !== null) {
            $resolution = [
                'decision' => $match->resolution_decision === 'void' ? 'void' : 'keep',
                'note' => (string) ($match->resolution_note ?? ''),
                'by' => self::person($match->resolver) ?? ['id' => '0', 'name' => 'Super Admin'],
                'at' => $match->resolution_at->toIso8601String(),
            ];
        }

        return [
            'submitted_by' => self::person($match->submitter) ?? ['id' => '0', 'name' => 'Scorer'],
            'submitted_at' => $match->submitted_at->toIso8601String(),
            'awaiting_side' => self::awaitingSide($match),
            'confirmations' => $match->confirmations
                ->map(fn (MatchConfirmation $row): array => [
                    'player' => self::person($row->player) ?? ['id' => (string) $row->player_id, 'name' => 'Player'],
                    'at' => $row->confirmed_at->toIso8601String(),
                ])
                ->values()
                ->all(),
            'dispute' => $match->dispute_at === null ? null : [
                'by' => self::person($match->disputer) ?? ['id' => (string) ($match->dispute_by ?? 0), 'name' => 'Player'],
                'reason' => (string) ($match->dispute_reason ?? ''),
                'at' => $match->dispute_at->toIso8601String(),
                'resolution' => $resolution,
            ],
            'verified_at' => $match->verified_at?->toIso8601String(),
            'verified_by' => self::person($match->verifier),
            'auto_verify_at' => $match->status === 'awaiting_confirmation' && $match->submitted_at !== null
                ? $match->submitted_at->copy()->addMilliseconds(self::AUTO_VERIFY_MS)->toIso8601String()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adminDispute(GameMatch $match): array
    {
        $match->loadMissing([
            'players.user', 'venue', 'court', 'session', 'scorer', 'creator',
            'tournament', 'disputer', 'submitter', 'resolver',
        ]);

        return [
            'match' => self::summary($match),
            'dispute' => [
                'by' => self::person($match->disputer) ?? ['id' => '0', 'name' => 'Player'],
                'reason' => (string) ($match->dispute_reason ?? ''),
                'at' => $match->dispute_at?->toIso8601String() ?? self::iso($match->created_at),
            ],
            'submitted_by' => self::person($match->submitter) ?? ['id' => '0', 'name' => 'Scorer'],
            'tournament' => $match->tournament === null ? null : [
                'id' => (string) $match->tournament->id,
                'name' => (string) $match->tournament->name,
                'slug' => (string) $match->tournament->slug,
            ],
            'resolution' => $match->resolution_at === null ? null : [
                'decision' => $match->resolution_decision === 'void' ? 'void' : 'keep',
                'note' => (string) ($match->resolution_note ?? ''),
                'by' => self::person($match->resolver) ?? ['id' => '0', 'name' => 'Super Admin'],
                'at' => $match->resolution_at->toIso8601String(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function eventViews(GameMatch $match): array
    {
        $match->loadMissing(['events.scorer']);
        $inputs = MatchEngine::inputs($match);
        $format = MatchEngine::format($match);
        $out = [];

        foreach ($match->events->values() as $index => $event) {
            $state = MatchScoring::replay(
                array_slice($inputs, 0, $index + 1),
                $format,
                (string) $match->match_type,
                (int) $match->first_serving,
            );

            $out[] = [
                'seq' => (int) $event->seq,
                'type' => $event->type === 'undo' ? 'undo' : 'rally',
                'winner' => $event->winner === null ? null : (int) $event->winner,
                'called_after' => $state['called'],
                'game' => $state['current'] + 1,
                'scorer' => self::person($event->scorer) ?? ['id' => '0', 'name' => 'Scorer'],
                'at' => self::iso($event->created_at),
            ];
        }

        return array_reverse($out);
    }

    public static function awaitingSide(GameMatch $match): ?int
    {
        if ($match->submitted_by === null) {
            return null;
        }

        $match->loadMissing('players');

        if ($match->players->where('side', 0)->contains(fn (MatchPlayer $p): bool => (int) $p->user_id === (int) $match->submitted_by)) {
            return 1;
        }

        if ($match->players->where('side', 1)->contains(fn (MatchPlayer $p): bool => (int) $p->user_id === (int) $match->submitted_by)) {
            return 0;
        }

        return null;
    }

    public static function canVerify(GameMatch $match, ?int $viewerId): bool
    {
        if ($viewerId === null) {
            return false;
        }

        $user = User::query()->find($viewerId);

        if ($user === null) {
            return false;
        }

        return $user->isSystemAdmin()
            || (MatchEngine::canManage($match, $viewerId) && ! self::onTeam($match, $viewerId));
    }

    public static function onTeam(GameMatch $match, ?int $viewerId): bool
    {
        return $viewerId !== null && $match->players
            ->contains(fn (MatchPlayer $player): bool => (int) $player->user_id === $viewerId);
    }

    public static function onSide(GameMatch $match, int $viewerId, int $side): bool
    {
        return $match->players
            ->where('side', $side)
            ->contains(fn (MatchPlayer $player): bool => (int) $player->user_id === $viewerId);
    }

    /**
     * @return array{id: string, name: string}|null
     */
    public static function person(?User $user): ?array
    {
        return $user === null ? null : ['id' => (string) $user->id, 'name' => (string) $user->name];
    }

    public static function status(mixed $status): string
    {
        return match ((string) $status) {
            'in_progress', 'live' => 'in_progress',
            'awaiting_confirmation', 'pending_confirmation' => 'awaiting_confirmation',
            'completed' => 'completed',
            'disputed' => 'disputed',
            'voided', 'cancelled' => 'voided',
            default => 'scheduled',
        };
    }

    private static function iso(mixed $value): string
    {
        return $value === null ? CarbonImmutable::now()->toIso8601String() : CarbonImmutable::instance($value)->toIso8601String();
    }
}
