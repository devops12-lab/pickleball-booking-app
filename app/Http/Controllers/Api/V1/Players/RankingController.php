<?php

namespace App\Http\Controllers\Api\V1\Players;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\PlayerRanking;
use App\Models\PlayerStatistic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RankingController extends Controller
{
    use ApiResponse;

    /**
     * View player's ranking position.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $rankings = PlayerRanking::where('user_id', $userId)
            ->latest('recorded_date')
            ->get();

        return $this->successResponse(
            data: $rankings,
            message: 'Player ranking history.'
        );
    }

    /**
     * View player personal statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        $stats = PlayerStatistic::where('user_id', $request->user()->id)->first();

        return $this->successResponse(
            data: $stats,
            message: 'Player match statistics retrieved.'
        );
    }
}
