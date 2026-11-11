<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourtResource;
use App\Http\Traits\ApiResponse;
use App\Models\Court;
use App\Services\BookingAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourtController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected BookingAvailabilityService $availabilityService
    ) {}

    /**
     * List public courts.
     */
    public function index(Request $request): JsonResponse
    {
        $facilityId = $request->query('facility_id');
        $tenantId = $request->query('tenant_id');

        $courts = Court::when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('is_active', true)
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
     * Show court details.
     */
    public function show(Court $court): JsonResponse
    {
        return $this->successResponse(
            data: new CourtResource($court->load(['facility', 'operatingHours'])),
            message: 'Court details retrieved.'
        );
    }

    /**
     * Public court availability check.
     */
    public function checkAvailability(Request $request, Court $court): JsonResponse
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $isAvailable = $this->availabilityService->isAvailable(
            $court,
            $validated['starts_at'],
            $validated['ends_at']
        );

        return $this->successResponse(
            data: [
                'court_id' => $court->id,
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'],
                'is_available' => $isAvailable,
                'hourly_rate' => (float) $court->hourly_rate,
            ],
            message: $isAvailable ? 'Court is available.' : 'Court is unavailable for the selected time window.'
        );
    }
}
