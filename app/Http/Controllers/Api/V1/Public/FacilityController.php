<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\FacilityResource;
use App\Http\Traits\ApiResponse;
use App\Models\Facility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacilityController extends Controller
{
    use ApiResponse;

    /**
     * List public facilities.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->query('tenant_id');

        $facilities = Facility::with('courts')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('is_active', true)
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse(
            data: FacilityResource::collection($facilities),
            meta: [
                'current_page' => $facilities->currentPage(),
                'total' => $facilities->total(),
                'per_page' => $facilities->perPage(),
            ]
        );
    }

    /**
     * Show facility details with courts.
     */
    public function show(Facility $facility): JsonResponse
    {
        return $this->successResponse(
            data: new FacilityResource($facility->load('courts')),
            message: 'Facility details retrieved.'
        );
    }
}
