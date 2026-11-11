<?php

namespace App\Http\Controllers\Api\V1\Public;

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
     * List public player rankings & leaderboard.
     */
    public function index(Request $request): JsonResponse
    {
        $category = $request->query('category', 'general');

        $rankings = PlayerRanking::with('user:id,name,avatar')
            ->where('category', $category)
            ->orderBy('rank')
            ->paginate($request->integer('per_page', 20));

        if ($rankings->isEmpty()) {
            // Fall back to top rated players from player_statistics
            $topPlayers = PlayerStatistic::with('user:id,name,avatar')
                ->orderByDesc('current_rating')
                ->paginate($request->integer('per_page', 20));

            return $this->successResponse(
                data: $topPlayers->items(),
                meta: [
                    'current_page' => $topPlayers->currentPage(),
                    'total' => $topPlayers->total(),
                ]
            );
        }

        return $this->successResponse(
            data: $rankings->items(),
            meta: [
                'current_page' => $rankings->currentPage(),
                'total' => $rankings->total(),
            ]
        );
    }
}
