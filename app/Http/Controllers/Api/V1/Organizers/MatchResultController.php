<?php

namespace App\Http\Controllers\Api\V1\Organizers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tournaments\SubmitMatchScoreRequest;
use App\Http\Resources\TournamentMatchResource;
use App\Http\Traits\ApiResponse;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class MatchResultController extends Controller
{
    use ApiResponse;

    /**
     * Submit score and match result for tournament.
     */
    public function submitScore(SubmitMatchScoreRequest $request, Tournament $tournament, TournamentMatch $match): JsonResponse
    {
        if ($tournament->organizer_id !== $request->user()->id && ! $request->user()->isSystemAdmin()) {
            return $this->errorResponse('Unauthorized to submit scores for this tournament.', code: Response::HTTP_FORBIDDEN);
        }

        if ($match->tournament_id !== $tournament->id) {
            return $this->errorResponse('Match does not belong to the specified tournament.', code: Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validated();

        $match->update([
            'score' => $validated['score'],
            'winner_id' => $validated['winner_id'],
            'status' => 'completed',
        ]);

        return $this->successResponse(
            data: new TournamentMatchResource($match->fresh(['participant1', 'participant2', 'winner'])),
            message: 'Match score recorded successfully.'
        );
    }
}
