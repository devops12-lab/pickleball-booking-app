<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\GeoLocation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /players?search=` — name search for hosts adding someone to a session.
 *
 * Only accounts with a player profile are listed, capped at eight, matching
 * the SPA's search. The endpoint returns no phone digits: `phone_last4` stays
 * null until the contract asks for it (the venue-scoped search on
 * `/clubs/{club}/players` is the one that shows last four).
 */
class PlayerSearchController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));

        if (mb_strlen($search) < 2) {
            return $this->successResponse(data: []);
        }

        $players = User::query()
            ->with('playerProfile')
            ->whereHas('playerProfile')
            ->whereRaw('lower(name) like ?', ['%'.$search.'%'])
            ->orderBy('name')
            ->limit(8)
            ->get();

        $results = $players
            ->filter(fn (User $player): bool => $player->playerProfile !== null)
            ->map(function (User $player): array {
                $chain = GeoLocation::chain($player->playerProfile->barangay_id);

                return [
                    'id' => (string) $player->id,
                    'name' => (string) $player->name,
                    'home_city' => $chain === null ? null : $chain['city']->name,
                    'phone_last4' => null,
                ];
            })
            ->values()
            ->all();

        return $this->successResponse(data: $results);
    }
}
