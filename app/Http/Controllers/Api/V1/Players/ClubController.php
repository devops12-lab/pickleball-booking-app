<?php

namespace App\Http\Controllers\Api\V1\Players;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Club;
use App\Models\ClubMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ClubController extends Controller
{
    use ApiResponse;

    /**
     * List clubs joined by the current player.
     */
    public function index(Request $request): JsonResponse
    {
        $memberships = ClubMember::with('club')
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->get();

        $clubs = $memberships
            ->filter(fn ($m) => $m->club !== null)
            ->map(fn ($m) => array_merge($m->club->toArray(), ['role' => $m->role]))
            ->values();

        return $this->successResponse(
            data: $clubs,
            message: 'Player clubs retrieved.'
        );
    }

    /**
     * Join a club.
     */
    public function join(Request $request, Club $club): JsonResponse
    {
        $user = $request->user();

        // Enforce maximum 3 clubs rule per requirement
        $activeClubsCount = ClubMember::where('user_id', $user->id)
            ->where('status', 'active')
            ->count();

        if ($activeClubsCount >= 3) {
            return $this->errorResponse(
                message: 'Player club limit reached. You can join a maximum of 3 clubs.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $membership = ClubMember::firstOrCreate(
            ['club_id' => $club->id, 'user_id' => $user->id],
            [
                'role' => 'member',
                'status' => $club->privacy === 'public' ? 'active' : 'pending',
                'joined_at' => now(),
            ]
        );

        $statusMsg = $membership->status === 'active'
            ? "Successfully joined {$club->name}."
            : "Membership request submitted for {$club->name}. Waiting for organizer approval.";

        return $this->successResponse(
            data: $membership->load('club'),
            message: $statusMsg,
            code: Response::HTTP_CREATED
        );
    }
}
