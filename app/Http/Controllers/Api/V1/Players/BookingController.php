<?php

namespace App\Http\Controllers\Api\V1\Players;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bookings\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Http\Traits\ApiResponse;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Court;
use App\Services\BookingAvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class BookingController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected BookingAvailabilityService $availabilityService
    ) {}

    /**
     * List current player's personal bookings.
     */
    public function index(Request $request): JsonResponse
    {
        $bookings = Booking::with('court.facility')
            ->where('user_id', $request->user()->id)
            ->latest('starts_at')
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse(
            data: BookingResource::collection($bookings),
            meta: [
                'current_page' => $bookings->currentPage(),
                'total' => $bookings->total(),
            ]
        );
    }

    /**
     * Reserve a court.
     */
    public function store(StoreBookingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $court = Court::findOrFail($validated['court_id']);

        $start = Carbon::parse($validated['starts_at']);
        $end = Carbon::parse($validated['ends_at']);
        $durationHours = max(1, $start->diffInMinutes($end) / 60);
        $totalAmount = round($durationHours * (float) $court->hourly_rate, 2);

        // Lock the court row to prevent double-booking under concurrency.
        $booking = DB::transaction(function () use ($court, $validated, $totalAmount, $request) {
            Court::lockForUpdate()->find($court->id);

            if (! $this->availabilityService->isAvailable($court, $validated['starts_at'], $validated['ends_at'])) {
                return null;
            }

            return Booking::create([
                'tenant_id' => $court->tenant_id,
                'court_id' => $court->id,
                'user_id' => $request->user()->id,
                'booking_reference' => 'CF-'.strtoupper(Str::random(8)),
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'],
                'total_amount' => $totalAmount,
                'status' => 'pending',
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        if ($booking === null) {
            return $this->errorResponse(
                message: 'Court is unavailable or conflicts with another schedule.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $this->successResponse(
            data: new BookingResource($booking->load('court')),
            message: 'Booking created. Please submit payment.',
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Show booking details.
     */
    public function show(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->user_id !== $request->user()->id) {
            return $this->errorResponse('Unauthorized booking access.', code: Response::HTTP_FORBIDDEN);
        }

        return $this->successResponse(
            data: new BookingResource($booking->load(['court.facility', 'payment'])),
            message: 'Booking details retrieved.'
        );
    }

    /**
     * Cancel personal booking.
     */
    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->user_id !== $request->user()->id) {
            return $this->errorResponse('Unauthorized booking access.', code: Response::HTTP_FORBIDDEN);
        }

        if ($booking->status === 'cancelled') {
            return $this->errorResponse('Booking is already cancelled.');
        }

        DB::transaction(function () use ($booking, $request): void {
            $oldStatus = $booking->status;
            $booking->update(['status' => 'cancelled']);

            BookingStatusHistory::create([
                'booking_id' => $booking->id,
                'changed_by' => $request->user()->id,
                'from_status' => $oldStatus,
                'to_status' => 'cancelled',
                'reason' => 'Cancelled by player',
            ]);
        });

        return $this->successResponse(
            data: new BookingResource($booking->fresh()),
            message: 'Booking successfully cancelled.'
        );
    }
}
