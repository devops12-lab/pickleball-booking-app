<?php

namespace App\Http\Controllers\Api\V1\Players;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tournaments\RegisterTournamentRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TournamentController extends Controller
{
    use ApiResponse;

    /**
     * List tournaments registered by player.
     */
    public function index(Request $request): JsonResponse
    {
        $registrations = TournamentParticipant::with(['tournament', 'category'])
            ->where('user_id', $request->user()->id)
            ->get();

        return $this->successResponse(
            data: $registrations,
            message: 'Player tournament registrations.'
        );
    }

    /**
     * Register for a tournament.
     */
    public function register(RegisterTournamentRequest $request, Tournament $tournament): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $participant = TournamentParticipant::firstOrCreate(
            ['tournament_id' => $tournament->id, 'user_id' => $user->id],
            array_merge($validated, [
                'status' => 'registered',
            ])
        );

        return $this->successResponse(
            data: $participant->load(['tournament', 'category']),
            message: "Successfully registered for tournament: {$tournament->name}.",
            code: Response::HTTP_CREATED
        );
    }
}
