<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClubResource;
use App\Http\Traits\ApiResponse;
use App\Models\Club;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClubController extends Controller
{
    use ApiResponse;

    /**
     * List public clubs.
     */
    public function index(Request $request): JsonResponse
    {
        $clubs = Club::where('privacy', 'public')
            ->withCount('members')
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse(
            data: ClubResource::collection($clubs),
            meta: [
                'current_page' => $clubs->currentPage(),
                'total' => $clubs->total(),
            ]
        );
    }

    /**
     * Show club details.
     */
    public function show(Club $club): JsonResponse
    {
        return $this->successResponse(
            data: new ClubResource($club->loadCount('members')),
            message: 'Club details retrieved.'
        );
    }
}
