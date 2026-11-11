<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Traits\ApiResponse;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CheckInController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext
    ) {}

    /**
     * Check-in a player/booking at the front desk.
     */
    public function checkIn(Booking $booking): JsonResponse
    {
        if ($booking->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized booking check-in.', code: Response::HTTP_FORBIDDEN);
        }

        BookingParticipant::updateOrCreate(
            ['booking_id' => $booking->id, 'user_id' => $booking->user_id],
            ['status' => 'checked_in']
        );

        return $this->successResponse(
            data: new BookingResource($booking->load(['court', 'participants'])),
            message: "Player for booking {$booking->booking_reference} successfully checked in."
        );
    }
}
