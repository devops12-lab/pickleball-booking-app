<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesClub;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Tenant;
use App\Support\Compat\Contract;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/clubs/{club}/subscription — the SPA's ClubSubscription contract.
 */
class SubscriptionController extends Controller
{
    use ApiResponse, AuthorizesClub;

    public function show(Tenant $club): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        $subscription = $club->subscription()->with(['plan', 'invoices'])->first();

        if (! $subscription) {
            return $this->errorResponse(
                'This club has no subscription yet.',
                code: Response::HTTP_NOT_FOUND
            );
        }

        return $this->successResponse(data: Contract::subscription($club, $subscription));
    }
}
