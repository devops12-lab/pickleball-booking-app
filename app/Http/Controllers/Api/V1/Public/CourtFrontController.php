<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourtFrontResource;
use App\Http\Traits\ApiResponse;
use App\Models\CourtFront;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

class CourtFrontController extends Controller
{
    use ApiResponse;

    /**
     * Public store front profile of a facility.
     */
    public function show(Tenant $tenant): JsonResponse
    {
        $front = CourtFront::query()
            ->with('tenant')
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($front === null) {
            return $this->errorResponse('This facility has no court front yet.', code: 404);
        }

        return $this->successResponse(
            data: new CourtFrontResource($front),
            message: 'Court front retrieved.'
        );
    }
}
