<?php

namespace App\Http\Controllers\Api\V1\Owners;

use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\StoreFacilityRequest;
use App\Http\Requests\Facilities\UpdateFacilityRequest;
use App\Http\Resources\FacilityResource;
use App\Http\Traits\ApiResponse;
use App\Models\Facility;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class FacilityController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext
    ) {}

    /**
     * List facilities belonging to the owner's tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();

        $facilities = Facility::with('courts')
            ->where('tenant_id', $tenantId)
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse(
            data: FacilityResource::collection($facilities),
            meta: [
                'current_page' => $facilities->currentPage(),
                'total' => $facilities->total(),
            ]
        );
    }

    /**
     * Create a new facility under the owner's tenant.
     */
    public function store(StoreFacilityRequest $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();

        $validated = $request->validated();

        $slug = Str::slug($validated['name']);
        if (Facility::where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(4));
        }

        $facility = Facility::create(array_merge($validated, [
            'tenant_id' => $tenantId,
            'slug' => $slug,
            'is_active' => true,
        ]));

        return $this->successResponse(
            data: new FacilityResource($facility),
            message: 'Facility created successfully.',
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Show facility details.
     */
    public function show(Facility $facility): JsonResponse
    {
        if ($facility->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized facility access.', code: Response::HTTP_FORBIDDEN);
        }

        return $this->successResponse(
            data: new FacilityResource($facility->load('courts')),
            message: 'Facility details retrieved.'
        );
    }

    /**
     * Update facility details.
     */
    public function update(UpdateFacilityRequest $request, Facility $facility): JsonResponse
    {
        if ($facility->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized facility access.', code: Response::HTTP_FORBIDDEN);
        }

        $facility->update($request->validated());

        return $this->successResponse(
            data: new FacilityResource($facility),
            message: 'Facility updated successfully.'
        );
    }

    /**
     * Delete facility.
     */
    public function destroy(Facility $facility): JsonResponse
    {
        if ($facility->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized facility access.', code: Response::HTTP_FORBIDDEN);
        }

        $facility->delete();

        return $this->successResponse(
            message: 'Facility deleted successfully.'
        );
    }
}
