<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\ClubMember;
use App\Support\Compat\CommunityView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /player/clubs` — the clubs a player belongs to or asked to join.
 *
 * Each entry pairs the club's public listing with the player's own membership
 * and whether they can host sessions for it. A banned member is left out, the
 * same as a rejected membership in the SPA.
 */
class PlayerClubController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $memberships = ClubMember::query()
            ->with(['club.homeVenue', 'plan', 'payments'])
            ->where('user_id', $userId)
            ->where('status', '!=', 'banned')
            ->get();

        $clubs = $memberships
            ->filter(fn (ClubMember $membership): bool => $membership->club !== null)
            ->map(function (ClubMember $membership) use ($userId): array {
                $club = $membership->club;

                return [
                    'club' => CommunityView::communityListing($club),
                    'membership' => CommunityView::memberSummary($membership),
                    'can_host' => $membership->status === 'active'
                        && ($club->status ?: 'active') === 'active'
                        && CommunityView::canManageCommunity($club, $userId),
                ];
            })
            ->values()
            ->all();

        return $this->successResponse(data: $clubs);
    }
}
