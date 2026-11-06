<?php

namespace App\Http\Controllers\Api\V1\Organizers;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\ClubMember;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    /**
     * Organizer / Club Manager dashboard.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $managedClubs = ClubMember::with('club')
            ->where('user_id', $userId)
            ->whereIn('role', ['manager', 'organizer'])
            ->where('status', 'active')
            ->get();

        $tournaments = Tournament::where('organizer_id', $userId)->get();

        return $this->successResponse(
            data: [
                'managed_clubs' => $managedClubs,
                'tournaments_organized' => $tournaments,
            ],
            message: 'Organizer dashboard retrieved.'
        );
    }
}
