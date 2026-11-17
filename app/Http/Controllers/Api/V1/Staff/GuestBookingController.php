<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bookings\StoreGuestBookingRequest;
use App\Http\Resources\BookingResource;
use App\Http\Traits\ApiResponse;
use App\Models\Booking;
use App\Models\Court;
use App\Services\BookingAvailabilityService;
use App\Services\TenantContextService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class GuestBookingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TenantContextService $tenantContext,
        private BookingAvailabilityService $availability
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();

        $bookings = Booking::with(['court'])
            ->where('tenant_id', $tenantId)
            ->whereNull('user_id')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->date, fn ($q, $d) => $q->whereDate('starts_at', $d))
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

    public function store(StoreGuestBookingRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tenantId = $this->tenantContext->getTenantId();

        $court = Court::where('id', $validated['court_id'])
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $court) {
            return $this->errorResponse(
                'Court not found in your venue.',
                code: Response::HTTP_NOT_FOUND
            );
        }

        $startsAt = $validated['starts_at'];
        $endsAt = $validated['ends_at'];

        if (! $this->availability->isAvailable($court, $startsAt, $endsAt)) {
            return $this->errorResponse(
                'The selected time slot is not available.',
                code: Response::HTTP_CONFLICT
            );
        }

        $booking = DB::transaction(function () use ($validated, $tenantId, $court, $request) {
            if ($this->availability->hasConflictingBooking($court, Carbon::parse($validated['starts_at']), Carbon::parse($validated['ends_at']))) {
                return null;
            }

            return Booking::create([
                'tenant_id' => $tenantId,
                'court_id' => $court->id,
                'user_id' => null,
                'guest_name' => $validated['guest_name'],
                'guest_email' => $validated['guest_email'],
                'guest_phone' => $validated['guest_phone'],
                'created_by' => $request->user()->id,
                'booking_reference' => strtoupper(Str::random(8)),
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'],
                'total_amount' => 0,
                'status' => 'confirmed',
                'source' => 'walk_in',
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        if ($booking === null) {
            return $this->errorResponse(
                'The slot was just taken. Please choose another time.',
                code: Response::HTTP_CONFLICT
            );
        }

        $booking->statusHistories()->create([
            'changed_by' => $request->user()->id,
            'from_status' => null,
            'to_status' => 'confirmed',
            'reason' => 'Walk-in guest booking created by staff',
        ]);

        return $this->successResponse(
            data: new BookingResource($booking->load('court')),
            message: 'Guest booking created.',
            code: Response::HTTP_CREATED
        );
    }

    public function show(Booking $booking): JsonResponse
    {
        if ($booking->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized booking access.', code: Response::HTTP_FORBIDDEN);
        }

        return $this->successResponse(
            data: new BookingResource($booking->load(['court', 'statusHistories'])),
            message: 'Guest booking details retrieved.'
        );
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->tenant_id !== $this->tenantContext->getTenantId()) {
            return $this->errorResponse('Unauthorized booking access.', code: Response::HTTP_FORBIDDEN);
        }

        if (in_array($booking->status, ['cancelled', 'completed'], true)) {
            return $this->errorResponse(
                "This booking is already {$booking->status}.",
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $oldStatus = $booking->status;

        DB::transaction(function () use ($booking, $request, $oldStatus): void {
            $booking->update(['status' => 'cancelled']);

            $booking->statusHistories()->create([
                'changed_by' => $request->user()->id,
                'from_status' => $oldStatus,
                'to_status' => 'cancelled',
                'reason' => 'Cancelled by staff',
            ]);
        });

        return $this->successResponse(
            data: new BookingResource($booking->fresh()),
            message: 'Guest booking cancelled.'
        );
    }
}
