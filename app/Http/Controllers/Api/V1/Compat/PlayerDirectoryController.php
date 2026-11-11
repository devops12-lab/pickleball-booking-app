<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesClub;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/clubs/{club}/players?search= — who staff can add to a walk-in.
 */
class PlayerDirectoryController extends Controller
{
    use ApiResponse, AuthorizesClub;

    public function index(Request $request, Tenant $club): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        $search = trim((string) $request->query('search', ''));

        if (mb_strlen($search) < 2) {
            return $this->successResponse(data: []);
        }

        $players = User::query()
            ->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(20)
            ->get();

        return $this->successResponse(
            data: $players->map(fn (User $user): array => [
                'id' => (string) $user->id,
                'name' => (string) $user->name,
                'home_city' => null,
                'phone_last4' => $user->phone ? substr((string) $user->phone, -4) : null,
            ])->all()
        );
    }
}
