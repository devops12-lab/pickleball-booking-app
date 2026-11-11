<?php

namespace App\Support\Compat;

use App\Models\OpenPlayParticipant;
use App\Models\OpenPlayQueueGame;
use App\Models\OpenPlaySession;
use Illuminate\Support\Collection;

/**
 * The live queue board (QueueState in packages/api/src/open-play-types.ts).
 * Match links are optional in the contract and there is no scoring link here,
 * so `match` is explicitly null rather than invented.
 */
class QueueView
{
    /**
     * @return array<string, mixed>
     */
    public static function state(OpenPlaySession $session): array
    {
        $session->loadMissing(['participants.user', 'courts', 'court', 'queueGames']);

        $participants = $session->participants->keyBy('id');
        $games = $session->queueGames->sortBy('id');
        $courts = $session->courts->isNotEmpty()
            ? $session->courts
            : collect($session->court === null ? [] : [$session->court]);

        $waiting = $session->participants
            ->where('queue_state', 'waiting')
            ->whereIn('status', ['checked_in', 'completed'])
            ->sortBy(fn (OpenPlayParticipant $p): array => [
                $p->queue_position ?? PHP_INT_MAX,
                $p->queue_since?->getTimestamp() ?? $p->created_at?->getTimestamp() ?? 0,
                $p->id,
            ])
            ->values();

        return [
            'session_id' => (string) $session->id,
            'version' => (int) $session->queue_version,
            'courts' => $courts->map(function ($court) use ($games, $participants): array {
                $game = $games->first(
                    fn (OpenPlayQueueGame $candidate): bool => (int) $candidate->court_id === (int) $court->id
                        && $candidate->ended_at === null
                );

                return [
                    'court_id' => (string) $court->id,
                    'court_name' => (string) $court->name,
                    'game' => $game === null ? null : self::game($game, $participants),
                ];
            })->values()->all(),
            'waiting' => $waiting
                ->values()
                ->map(fn (OpenPlayParticipant $p, int $index): array => [
                    'participant_id' => (string) $p->id,
                    'player' => self::player($p),
                    'position' => $index + 1,
                    'games_played' => (int) $p->games_played,
                    'waiting_since' => ($p->queue_since ?? $p->created_at ?? now())->toIso8601String(),
                ])
                ->all(),
            'resting' => $session->participants
                ->where('queue_state', 'resting')
                ->whereIn('status', ['checked_in', 'completed'])
                ->sortBy('id')
                ->values()
                ->map(fn (OpenPlayParticipant $p): array => [
                    'participant_id' => (string) $p->id,
                    'player' => self::player($p),
                    'games_played' => (int) $p->games_played,
                ])
                ->all(),
            'recent_games' => $games
                ->filter(fn (OpenPlayQueueGame $game): bool => $game->ended_at !== null)
                ->sortByDesc(fn (OpenPlayQueueGame $game): int => $game->ended_at->getTimestamp() * 1000 + $game->id)
                ->take(6)
                ->map(fn (OpenPlayQueueGame $game): array => self::game($game, $participants) + [
                    'ended_at' => $game->ended_at->toIso8601String(),
                    'winner' => $game->winner,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, OpenPlayParticipant>  $participants
     * @return array<string, mixed>
     */
    private static function game(OpenPlayQueueGame $game, Collection $participants): array
    {
        return [
            'id' => (string) $game->id,
            'court_id' => (string) $game->court_id,
            'started_at' => $game->started_at->toIso8601String(),
            'team_a' => self::team($game->team_a, $participants),
            'team_b' => self::team($game->team_b, $participants),
            'match' => null,
        ];
    }

    /**
     * @param  list<int|string>|null  $ids
     * @param  Collection<int, OpenPlayParticipant>  $participants
     * @return list<array{id: string, name: string}>
     */
    private static function team(?array $ids, Collection $participants): array
    {
        return collect($ids ?? [])
            ->map(function ($id) use ($participants): ?array {
                $participant = $participants->get((int) $id);

                return $participant === null ? null : self::player($participant);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{id: string, name: string}
     */
    public static function player(OpenPlayParticipant $participant): array
    {
        $participant->loadMissing('user');

        return [
            'id' => (string) $participant->user_id,
            'name' => (string) ($participant->user?->name ?? 'Player'),
        ];
    }

    public static function touch(OpenPlaySession $session): void
    {
        $session->increment('queue_version');
        $session->refresh();
    }

    public static function nextPosition(OpenPlaySession $session): int
    {
        return (int) $session->participants()
            ->where('queue_state', 'waiting')
            ->max('queue_position') + 1;
    }
}
