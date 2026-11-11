<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\OpenPlaySessionResource;
use App\Http\Traits\ApiResponse;
use App\Models\OpenPlaySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
