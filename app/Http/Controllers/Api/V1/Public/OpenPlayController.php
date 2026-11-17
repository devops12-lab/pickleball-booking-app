<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\OpenPlaySessionResource;
use App\Http\Traits\ApiResponse;
use App\Models\OpenPlayParticipant;
use App\Models\OpenPlaySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OpenPlayController extends Controller
{
    use ApiResponse;

    /**
     * List public open play sessions.
     */
    public function index(Request $request): JsonResponse
    {
        $sessions = OpenPlaySession::with(['court.facility'])
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse(
            data: OpenPlaySessionResource::collection($sessions),
            meta: [
                'current_page' => $sessions->currentPage(),
                'total' => $sessions->total(),
            ]
        );
    }

    /**
     * Show open play session details.
     */
    public function show(OpenPlaySession $session): JsonResponse
    {
        return $this->successResponse(
            data: new OpenPlaySessionResource($session->load(['court.facility', 'participants'])),
            message: 'Open play session details retrieved.'
        );
    }

    /**
     * Show final results (top 3 by wins) for a completed open-play session.
     */
    public function results(OpenPlaySession $session): JsonResponse
    {
        if ($session->status !== 'completed') {
            return $this->errorResponse(
                'Results are only available for completed sessions.',
                code: Response::HTTP_NOT_FOUND
            );
        }

        $games = $session->queueGames()
            ->whereNotNull('winner')
            ->whereNotNull('ended_at')
            ->get();

        $wins = [];
        $gamesPlayed = [];

        foreach ($games as $game) {
            $allParticipants = array_merge($game->team_a ?? [], $game->team_b ?? []);

            foreach ($allParticipants as $pid) {
                $gamesPlayed[$pid] = ($gamesPlayed[$pid] ?? 0) + 1;
            }

            $winningTeam = $game->winner === 'a' ? ($game->team_a ?? []) : ($game->team_b ?? []);

            foreach ($winningTeam as $pid) {
                $wins[$pid] = ($wins[$pid] ?? 0) + 1;
            }
        }

        $ranked = collect($gamesPlayed)
            ->map(fn (int $gp, int|string $pid): array => [
                'participant_id' => (int) $pid,
                'wins' => $wins[$pid] ?? 0,
                'games_played' => $gp,
            ])
            ->sort(fn (array $a, array $b): int => ($b['wins'] <=> $a['wins']) ?: ($b['games_played'] <=> $a['games_played']))
            ->values()
            ->take(3);

        $participantIds = $ranked->pluck('participant_id')->all();
        $participants = OpenPlayParticipant::whereIn('id', $participantIds)
            ->with('user')
            ->get()
            ->keyBy('id');

        $placements = $ranked->values()->map(fn (array $row, int $index): array => [
            'place' => $index + 1,
            'participant' => [
                'id' => (string) $row['participant_id'],
                'name' => (string) ($participants[$row['participant_id']]?->user?->name ?? 'Unknown'),
                'players' => $participants[$row['participant_id']]?->user ? [[
                    'id' => (string) $participants[$row['participant_id']]->user->id,
                    'name' => (string) $participants[$row['participant_id']]->user->name,
                ]] : [],
            ],
            'wins' => $row['wins'],
            'games_played' => $row['games_played'],
        ])->all();

        return $this->successResponse(
            data: [
                'session' => [
                    'id' => $session->id,
                    'title' => $session->title,
                    'starts_at' => $session->starts_at?->toISOString(),
                    'status' => $session->status,
                ],
                'placements' => $placements,
            ],
            message: 'Open play session results retrieved.'
        );
    }
}
