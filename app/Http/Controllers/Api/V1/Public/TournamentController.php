<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\TournamentResource;
use App\Http\Traits\ApiResponse;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
