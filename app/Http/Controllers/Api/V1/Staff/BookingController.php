<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bookings\UpdateBookingStatusRequest;
use App\Http\Resources\BookingResource;
use App\Http\Traits\ApiResponse;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
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
     * List bookings for staff operations.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();

        $bookings = Booking::with(['court', 'user'])
            ->where('tenant_id', $tenantId)
            ->when($request->date, fn ($q, $d) => $q->whereDate('starts_at', $d))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('starts_at')
            ->paginate($request->integer('per_page', 25));

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
            data: new BookingResource($booking->load(['court', 'user', 'participants', 'payment'])),
            message: 'Booking details retrieved.'
        );
    }

    /**
     * Update booking status (e.g. mark completed, cancel no-show).
     */
    public function updateStatus(UpdateBookingStatusRequest $request, Booking $booking): JsonResponse
    {
        if ($booking->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized booking access.', code: Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validated();

        $oldStatus = $booking->status;
        $booking->update(['status' => $validated['status']]);

        BookingStatusHistory::create([
            'booking_id' => $booking->id,
            'changed_by' => $request->user()->id,
            'from_status' => $oldStatus,
            'to_status' => $validated['status'],
            'reason' => $validated['reason'] ?? 'Updated by staff',
        ]);

        return $this->successResponse(
            data: new BookingResource($booking->fresh()),
            message: "Booking status changed to {$booking->status}."
        );
    }
}
