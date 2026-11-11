<?php

namespace App\Http\Controllers\Api\V1\Players;

use App\Http\Controllers\Controller;
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
     * List open-play sessions joined by player.
     */
    public function index(Request $request): JsonResponse
    {
        $sessions = OpenPlayParticipant::with(['session.court.facility'])
            ->where('user_id', $request->user()->id)
            ->get();

        return $this->successResponse(
            data: $sessions,
            message: 'Player open play registrations.'
        );
    }

    /**
     * Join an open-play session.
     */
    public function join(Request $request, OpenPlaySession $session): JsonResponse
    {
        $user = $request->user();

        if ($session->participants()->count() >= $session->max_participants) {
            return $this->errorResponse('Open play session is already fully booked.', code: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $participant = OpenPlayParticipant::firstOrCreate(
            ['open_play_session_id' => $session->id, 'user_id' => $user->id],
            ['check_in_status' => 'registered']
        );

        return $this->successResponse(
            data: $participant->load('session'),
            message: "Successfully registered for open play session: {$session->title}.",
            code: Response::HTTP_CREATED
        );
    }
}
