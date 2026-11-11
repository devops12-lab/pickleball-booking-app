<?php

namespace App\Http\Controllers\Api\V1\Players;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Traits\ApiResponse;
use App\Models\Booking;
use App\Models\ClubMember;
use App\Models\PlayerStatistic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    /**
     * Player personal dashboard.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $upcomingBookings = Booking::with('court.facility')
            ->where('user_id', $user->id)
            ->where('starts_at', '>=', now())
            ->whereIn('status', ['pending', 'confirmed'])
            ->orderBy('starts_at')
            ->take(5)
            ->get();

        $clubsCount = ClubMember::where('user_id', $user->id)
            ->where('status', 'active')
            ->count();

        $statistics = PlayerStatistic::where('user_id', $user->id)->first();

        return $this->successResponse(
            data: [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->avatar,
                ],
                'upcoming_bookings' => BookingResource::collection($upcomingBookings),
                'joined_clubs_count' => $clubsCount,
                'statistics' => $statistics,
            ],
            message: 'Player dashboard retrieved.'
        );
    }
}
