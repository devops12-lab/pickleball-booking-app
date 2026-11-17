<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\TournamentResource;
use App\Http\Traits\ApiResponse;
use App\Models\Tournament;
use App\Support\Compat\CompetitionView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TournamentController extends Controller
{
    use ApiResponse;

    /**
     * List public tournaments.
     */
    public function index(Request $request): JsonResponse
    {
        $tournaments = Tournament::with(['tenant', 'categories'])
            ->whereIn('status', ['upcoming', 'registration_open', 'in_progress'])
            ->orderBy('starts_at')
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse(
            data: TournamentResource::collection($tournaments),
            meta: [
                'current_page' => $tournaments->currentPage(),
                'total' => $tournaments->total(),
            ]
        );
    }

    /**
     * Show tournament details.
     */
    public function show(Tournament $tournament): JsonResponse
    {
        return $this->successResponse(
            data: new TournamentResource($tournament->load(['tenant', 'categories', 'matches'])),
            message: 'Tournament details retrieved.'
        );
    }

    /**
     * Show final results (1st, 2nd, 3rd) for a completed tournament.
     */
    public function results(Tournament $tournament): JsonResponse
    {
        if ($tournament->status !== 'completed') {
            return $this->errorResponse(
                'Results are only available for completed tournaments.',
                code: Response::HTTP_NOT_FOUND
            );
        }

        $tournament->load([
            'champion.user', 'champion.partner',
            'runnerUp.user', 'runnerUp.partner',
            'thirdPlace.user', 'thirdPlace.partner',
        ]);

        $placements = collect([
            [1, $tournament->champion],
            [2, $tournament->runnerUp],
            [3, $tournament->thirdPlace],
        ])
            ->filter(fn (array $item): bool => $item[1] !== null)
            ->map(fn (array $item): array => [
                'place' => $item[0],
                'participant' => [
                    'id' => (string) $item[1]->id,
                    'name' => CompetitionView::entryName($item[1]),
                    'seed' => $item[1]->seed,
                    'players' => CompetitionView::entryPlayers($item[1]),
                ],
            ])
            ->values()
            ->all();

        return $this->successResponse(
            data: [
                'tournament' => [
                    'id' => $tournament->id,
                    'name' => $tournament->name,
                    'format' => $tournament->format,
                    'starts_at' => $tournament->starts_at?->toISOString(),
                    'status' => $tournament->status,
                ],
                'placements' => $placements,
            ],
            message: 'Tournament results retrieved.'
        );
    }
}
