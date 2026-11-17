<?php

namespace App\Http\Controllers\Api\V1\Players;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\PlatformSetting;
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

        $maxClubs = PlatformSetting::get('max_clubs_per_player', 3);
        $activeClubsCount = ClubMember::where('user_id', $user->id)
            ->where('status', 'active')
            ->count();

        if ($activeClubsCount >= $maxClubs) {
            return $this->errorResponse(
                message: "Player club limit reached. You can join a maximum of {$maxClubs} clubs.",
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

    /**
     * Leave a club.
     */
    public function leave(Request $request, Club $club): JsonResponse
    {
        $user = $request->user();

        $membership = ClubMember::where('club_id', $club->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'pending'])
            ->first();

        if (! $membership) {
            return $this->errorResponse(
                message: 'You are not a member of this club.',
                code: Response::HTTP_NOT_FOUND
            );
        }

        if ($club->creator_id === $user->id) {
            return $this->errorResponse(
                message: 'Club creators cannot leave their own club. Transfer ownership or delete the club instead.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $membership->delete();

        return $this->successResponse(
            message: "You have left {$club->name}."
        );
    }

    /**
     * List available clubs the player can join.
     */
    public function available(Request $request): JsonResponse
    {
        $user = $request->user();

        $joinedClubIds = ClubMember::where('user_id', $user->id)
            ->whereIn('status', ['active', 'pending'])
            ->pluck('club_id');

        $clubs = Club::where('status', 'active')
            ->whereNotIn('id', $joinedClubIds)
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->withCount('members')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return $this->successResponse(
            data: $clubs,
            meta: [
                'current_page' => $clubs->currentPage(),
                'total' => $clubs->total(),
            ]
        );
    }
}
