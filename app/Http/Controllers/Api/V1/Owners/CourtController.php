<?php

namespace App\Http\Controllers\Api\V1\Owners;

use App\Http\Controllers\Controller;
use App\Http\Requests\Courts\StoreCourtRequest;
use App\Http\Requests\Courts\UpdateCourtRequest;
use App\Http\Resources\CourtResource;
use App\Http\Traits\ApiResponse;
use App\Models\Court;
use App\Models\Facility;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CourtController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext
    ) {}

    /**
     * List all courts under owner's tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();

        $courts = Court::where('tenant_id', $tenantId)
            ->when($request->facility_id, fn ($q, $f) => $q->where('facility_id', $f))
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse(
            data: CourtResource::collection($courts),
            meta: [
                'current_page' => $courts->currentPage(),
                'total' => $courts->total(),
            ]
        );
    }

    /**
     * Create a court under owner's facility.
     */
    public function store(StoreCourtRequest $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();
        $validated = $request->validated();

        $facility = Facility::where('id', $validated['facility_id'])
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $facility) {
            return $this->errorResponse('Facility does not belong to this tenant.', code: Response::HTTP_FORBIDDEN);
        }

        $court = Court::create(array_merge($validated, [
            'tenant_id' => $tenantId,
            'is_active' => true,
        ]));

        return $this->successResponse(
            data: new CourtResource($court),
            message: 'Court created successfully.',
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Show court details.
     */
    public function show(Court $court): JsonResponse
    {
        if ($court->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized court access.', code: Response::HTTP_FORBIDDEN);
        }

        return $this->successResponse(
            data: new CourtResource($court->load(['facility', 'operatingHours'])),
            message: 'Court details retrieved.'
        );
    }

    /**
     * Update court.
     */
    public function update(UpdateCourtRequest $request, Court $court): JsonResponse
    {
        if ($court->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized court access.', code: Response::HTTP_FORBIDDEN);
        }

        $court->update($request->validated());

        return $this->successResponse(
            data: new CourtResource($court),
            message: 'Court updated successfully.'
        );
    }

    /**
     * Delete court.
     */
    public function destroy(Court $court): JsonResponse
    {
        if ($court->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized court access.', code: Response::HTTP_FORBIDDEN);
        }

        $court->delete();

        return $this->successResponse(
            message: 'Court deleted successfully.'
        );
    }
}
