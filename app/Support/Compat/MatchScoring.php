<?php

namespace App\Support\Compat;

/**
 * The same pickleball scoring and ELO rules as packages/api/src/scoring.ts and
 * rating.ts. The server replays the immutable event log; screens use the same
 * replay so they always show the official score.
 */
class MatchScoring
{
    public const RATING_VERSION = 'elo-v1';

    /**
     * @param  list<array{type: string, winner: int|null}>  $events
     * @param  array{target: int, win_by: int, best_of: int, scoring: string}  $format
     * @return array<string, mixed>
     */
    public static function replay(array $events, array $format, string $matchType, int $firstServing = 0): array
    {
        $standing = [];

        foreach ($events as $event) {
            if (($event['type'] ?? '') === 'rally') {
                $standing[] = (int) $event['winner'];
            } else {
                array_pop($standing);
            }
        }

        $target = (int) ($format['target'] ?? 11);
        $winBy = (int) ($format['win_by'] ?? 2);
        $bestOf = (int) ($format['best_of'] ?? 1);
        $scoring = ($format['scoring'] ?? 'side_out') === 'rally' ? 'rally' : 'side_out';
        $doublesSideOut = $scoring === 'side_out' && $matchType === 'doubles';
        $rules = ['target' => $target, 'win_by' => $winBy];

        $games = [['points' => [0, 0], 'winner' => null]];
        $won = [0, 0];
        $serving = $firstServing === 1 ? 1 : 0;
        $server = $doublesSideOut ? 2 : null;
        $winner = null;

        foreach ($standing as $side) {
            if ($winner !== null) {
                break;
            }

            $index = count($games) - 1;
            $game = &$games[$index];

            if ($scoring === 'rally' || $side === $serving) {
                $game['points'][$side]++;
                $serving = $side;
            } elseif ($doublesSideOut && $server === 1) {
                $server = 2;
            } else {
                $serving = $side;
                $server = $doublesSideOut ? 1 : null;
            }

            $gameWinner = self::gameWinner($game['points'], $rules);

            if ($gameWinner !== null) {
                $game['winner'] = $gameWinner;
                $won[$gameWinner]++;

                if ($won[$gameWinner] >= self::gamesToWin($bestOf)) {
                    $winner = $gameWinner;
                } else {
                    $games[] = ['points' => [0, 0], 'winner' => null];
                    // Sides take turns starting games.
                    $serving = ($index + 1) % 2 === 0
                        ? ($firstServing === 1 ? 1 : 0)
                        : ($firstServing === 1 ? 0 : 1);
                    $server = $doublesSideOut ? 2 : null;
                }
            }

            unset($game);
        }

        $index = count($games) - 1;
        $game = $games[$index];
        $gamePoint = null;

        if ($winner === null) {
            $candidates = $scoring === 'rally' ? [0, 1] : [$serving];

            foreach ($candidates as $candidate) {
                if (self::oneAway($game['points'], $candidate, $rules)) {
                    $gamePoint = $candidate;
                    break;
                }
            }
        }

        $matchPoint = $gamePoint !== null && $won[$gamePoint] + 1 >= self::gamesToWin($bestOf)
            ? $gamePoint
            : null;

        $other = $serving === 0 ? 1 : 0;
        $called = $scoring === 'rally'
            ? $game['points'][0].'-'.$game['points'][1]
            : $game['points'][$serving].'-'.$game['points'][$other].($server ? '-'.$server : '');

        return [
            'games' => $games,
            'current' => $index,
            'serving' => $serving,
            'server' => $server,
            'games_won' => $won,
            'winner' => $winner,
            'game_point' => $gamePoint,
            'match_point' => $matchPoint,
            'called' => $called,
            'rallies' => count($standing),
        ];
    }

    /**
     * "11-7" or "11-7, 9-11, 11-4", from side A's view.
     *
     * @param  array{games: list<array{points: array{0: int, 1: int}, winner: int|null}>}  $state
     */
    public static function finalScore(array $state): string
    {
        $out = [];

        foreach ($state['games'] as $game) {
            if ($game['winner'] !== null || $game['points'][0] + $game['points'][1] > 0) {
                $out[] = $game['points'][0].'-'.$game['points'][1];
            }
        }

        return implode(', ', $out);
    }

    /**
     * @param  array{0: array<int, array{id: string|int, rating: int, games: int}>, 1: array<int, array{id: string|int, rating: int, games: int}>}  $teams
     * @return list<array{id: int, old: int, change: int, new: int}>
     */
    public static function ratingChanges(array $teams, int $winner, array $rules = []): array
    {
        $initial = (int) ($rules['initial'] ?? 1200);
        $provisional = (int) ($rules['provisional_matches'] ?? 5);
        $kProvisional = (int) ($rules['k_provisional'] ?? 32);
        $kEstablished = (int) ($rules['k_established'] ?? 24);
        $average = fn (array $team): float => count($team) === 0
            ? $initial
            : array_sum(array_column($team, 'rating')) / count($team);
        $a = $average($teams[0]);
        $b = $average($teams[1]);
        $out = [];

        foreach ([0, 1] as $side) {
            $expected = 1 / (1 + 10 ** ((($side === 0 ? $b : $a) - ($side === 0 ? $a : $b)) / 400));
            $result = $side === $winner ? 1 : 0;

            foreach ($teams[$side] as $player) {
                $k = (int) $player['games'] < $provisional ? $kProvisional : $kEstablished;
                $change = self::jsRound($k * ($result - $expected));
                $out[] = [
                    'id' => (int) $player['id'],
                    'old' => (int) $player['rating'],
                    'change' => $change,
                    'new' => (int) $player['rating'] + $change,
                ];
            }
        }

        return $out;
    }

    /**
     * JavaScript Math.round: nearest integer, .5 toward +Infinity.
     */
    private static function jsRound(float $value): int
    {
        return (int) floor($value + 0.5);
    }

    private static function gamesToWin(int $bestOf): int
    {
        return $bestOf === 3 ? 2 : 1;
    }

    /**
     * @param  array{0: int, 1: int}  $points
     * @param  array{target: int, win_by: int}  $format
     */
    private static function gameWinner(array $points, array $format): ?int
    {
        foreach ([0, 1] as $side) {
            $other = $side === 0 ? 1 : 0;

            if ($points[$side] >= $format['target'] && $points[$side] - $points[$other] >= $format['win_by']) {
                return $side;
            }
        }

        return null;
    }

    /**
     * @param  array{0: int, 1: int}  $points
     * @param  array{target: int, win_by: int}  $format
     */
    private static function oneAway(array $points, int $side, array $format): bool
    {
        $next = $points;
        $next[$side]++;

        return self::gameWinner($next, $format) === $side;
    }
}
