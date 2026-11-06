<?php

namespace App\Http\Controllers\Api\V1\Owners;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Traits\ApiResponse;
use App\Models\Booking;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BookingController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext
    ) {}

    /**
     * List all facility bookings for owner's tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();

        $bookings = Booking::with(['court', 'user'])
            ->where('tenant_id', $tenantId)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('starts_at')
            ->paginate($request->integer('per_page', 20));

        return $this->successResponse(
            data: BookingResource::collection($bookings),
            meta: [
                'current_page' => $bookings->currentPage(),
                'total' => $bookings->total(),
            ]
        );
    }

    /**
     * Show booking details.
     */
    public function show(Booking $booking): JsonResponse
    {
        if ($booking->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized booking access.', code: Response::HTTP_FORBIDDEN);
        }

        return $this->successResponse(
            data: new BookingResource($booking->load(['court', 'user', 'participants', 'statusHistories', 'payment'])),
            message: 'Booking details retrieved.'
        );
    }
}
