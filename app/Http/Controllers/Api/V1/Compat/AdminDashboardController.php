<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesPlatformAdmin;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Support\Compat\AdminView;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/admin/dashboard — platform counts, revenue, trials ending,
 * the review queue and the latest admin activity.
 */
class AdminDashboardController extends Controller
{
    use ApiResponse, AuthorizesPlatformAdmin;

    public function index(): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        return $this->successResponse(data: AdminView::dashboard());
    }
}
