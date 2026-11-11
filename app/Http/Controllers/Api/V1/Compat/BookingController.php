<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesClub;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Booking;
use App\Models\Court;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BookingAvailabilityService;
use App\Services\BookingHoldService;
use App\Support\Compat\Contract;
use App\Support\Compat\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/clubs/{club}/bookings/… — the venue side of a booking: walk-ins,
 * recording payments, checking e-wallet receipts, and cancelling.
 */
class BookingController extends Controller
{
    use ApiResponse, AuthorizesClub;

    public function __construct(
        protected BookingAvailabilityService $availabilityService,
        protected BookingHoldService $holds,
    ) {}

    public function show(Tenant $club, Booking $booking): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $booking->tenant_id)) {
            return $this->errorResponse('Booking not found in this club.', code: Response::HTTP_NOT_FOUND);
        }

        $this->holds->cancelExpired();

        return $this->successResponse(data: Contract::bookingDetail($booking->refresh()));
    }

    public function walkIn(Request $request, Tenant $club): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        $data = $request->validate([
            'court_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'player_id' => ['nullable', 'integer'],
            'guest_name' => ['nullable', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $court = Court::where('id', $data['court_id'])->where('tenant_id', $club->id)->first();

        if (! $court) {
            return $this->errorResponse('Court not found in this club.', code: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $player = isset($data['player_id']) ? User::find($data['player_id']) : null;

        if (! $player) {
            // This backend's bookings require a user account; anonymous walk-ins
            // need a guest record that does not exist here yet.
            return $this->errorResponse(
                'Walk-in bookings need an existing player_id in this backend.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // The availability service compares naive local times against the courts'
        // stored hours, so convert the SPA's offset-aware instants into the club's
        // timezone for the check, then store the booking itself in UTC.
        $timezone = $club->venueProfile?->timezone ?: 'Asia/Manila';
        $startsLocal = CarbonImmutable::parse($data['starts_at'])->setTimezone($timezone);
        $endsLocal = CarbonImmutable::parse($data['ends_at'])->setTimezone($timezone);

        if (! $this->availabilityService->isAvailable(
            $court,
            $startsLocal->toDateTimeString(),
            $endsLocal->toDateTimeString(),
            null,
            $timezone
        )) {
            return $this->errorResponse('That slot is no longer available.', code: Response::HTTP_CONFLICT);
        }

        $hours = ($endsLocal->getTimestamp() - $startsLocal->getTimestamp()) / 3600;
        $total = number_format((float) $court->hourly_rate * $hours, 2, '.', '');

        $booking = Booking::create([
            'tenant_id' => $club->id,
            'court_id' => $court->id,
            'user_id' => $player->id,
            'created_by' => $request->user()->id,
            'booking_reference' => 'BK-'.strtoupper(Str::random(8)),
            'starts_at' => $startsLocal->setTimezone('UTC')->toDateTimeString(),
            'ends_at' => $endsLocal->setTimezone('UTC')->toDateTimeString(),
            'total_amount' => $total,
            'status' => 'confirmed',
            'source' => 'staff_walk_in',
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->successResponse(
            data: Contract::bookingDetail($booking->fresh()),
            message: 'Walk-in booked.',
            code: Response::HTTP_CREATED
        );
    }

    public function recordPayment(Request $request, Tenant $club, Booking $booking): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $booking->tenant_id)) {
            return $this->errorResponse('Booking not found in this club.', code: Response::HTTP_NOT_FOUND);
        }

        $data = $request->validate([
            'amount' => ['required', 'regex:/^-?\d+\.\d{2}$/'],
            'method' => ['required', 'in:cash,gcash_at_venue,maya_at_venue,card_at_venue,other'],
            'reference_number' => ['nullable', 'string', 'max:100'],
        ]);

        $payment = Payment::create([
            'tenant_id' => $club->id,
            'booking_id' => $booking->id,
            'user_id' => $request->user()->id,
            'amount' => $data['amount'],
            'currency' => Contract::CURRENCY,
            'payment_method' => Contract::backendPaymentMethod($data['method']),
            'transaction_reference' => $data['reference_number'] ?? 'PMT-'.strtoupper(Str::random(10)),
            'status' => 'verified',
        ]);

        return $this->successResponse(
            data: Contract::paymentRecord($payment->load('user')),
            message: 'Payment recorded.',
            code: Response::HTTP_CREATED
        );
    }

    public function adjustPayment(Request $request, Tenant $club, Booking $booking, Payment $payment): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $booking->tenant_id)
            || (int) $payment->booking_id !== (int) $booking->id) {
            return $this->errorResponse('Payment not found.', code: Response::HTTP_NOT_FOUND);
        }

        $data = $request->validate([
            'amount' => ['required', 'regex:/^-?\d+\.\d{2}$/'],
            'adjustment_type' => ['required', 'in:refund,correction'],
            'reason' => ['required', 'string', 'max:300'],
        ]);

        $held = (float) $payment->amount - (float) $payment->refunds()->sum('amount');
        $amount = (float) $data['amount'];

        if ($amount <= 0) {
            return $this->validationError(['amount' => ['Enter an amount above zero.']]);
        }

        if ($amount > $held) {
            return $this->validationError([
                'amount' => ['Only ₱'.number_format($held, 2).' of this payment is left to adjust.'],
            ]);
        }

        $refund = Refund::query()->create([
            'payment_id' => $payment->id,
            'processed_by' => $request->user()->id,
            'amount' => number_format($amount, 2, '.', ''),
            'reason' => $data['reason'],
            'status' => 'completed',
            'adjustment_type' => $data['adjustment_type'],
        ]);

        return $this->successResponse(
            data: Contract::adjustment($refund),
            message: 'Payment adjusted.',
            code: Response::HTTP_CREATED,
        );
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validationError(array $errors): JsonResponse
    {
        return $this->errorResponse(
            message: 'The given data was invalid.',
            errors: $errors,
            code: Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * Bookings with a receipt waiting for staff to check.
     */
    public function receipts(Tenant $club): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        $this->holds->cancelExpired();

        $bookings = Booking::query()
            ->where('tenant_id', $club->id)
            ->whereHas('receipts', fn ($query) => $query->where('status', 'in_review'))
            ->with(['user', 'creator', 'payments', 'receipts.reviewer'])
            ->orderBy('starts_at')
            ->get();

        return $this->successResponse(
            data: $bookings->map(fn (Booking $booking) => Contract::booking($booking))->all()
        );
    }

    public function reviewReceipt(Request $request, Tenant $club, Booking $booking, string $action): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $booking->tenant_id)) {
            return $this->errorResponse('Booking not found in this club.', code: Response::HTTP_NOT_FOUND);
        }

        $receipt = $booking->receipts()->latest('id')->first();

        if (! $receipt || $receipt->status !== 'in_review') {
            return $this->errorResponse('There is no receipt waiting to be checked.', code: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $rejection = null;

        if ($action === 'approve') {
            $receipt->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $paid = (float) $booking->payments()
                ->whereIn('status', ['verified', 'paid'])
                ->sum('amount');
            $due = (float) $booking->total_amount - $paid;

            if ($due > 0) {
                $reference = $receipt->reference_number;
                $taken = Payment::where('transaction_reference', $reference)->exists();

                Payment::create([
                    'tenant_id' => $club->id,
                    'booking_id' => $booking->id,
                    'user_id' => $request->user()->id,
                    'amount' => number_format($due, 2, '.', ''),
                    'currency' => Contract::CURRENCY,
                    'payment_method' => $booking->payment_method === 'maya' ? 'maya' : 'gcash',
                    'transaction_reference' => $taken || $reference === ''
                        ? 'PMT-'.strtoupper(Str::random(10))
                        : $reference,
                    'status' => 'verified',
                ]);
            }

            if ($booking->status === 'pending') {
                $booking->update(['status' => 'confirmed']);
            }
        } else {
            $data = $request->validate([
                'reason' => ['required', 'string', 'max:255'],
            ]);

            $rejection = $data['reason'];

            $receipt->update([
                'status' => 'rejected',
                'rejection_reason' => $rejection,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        }

        if ($booking->user_id !== null) {
            $wallet = $booking->payment_method === 'maya' ? 'Maya' : 'GCash';

            if ($rejection === null) {
                NotificationService::send(
                    (int) $booking->user_id,
                    'bookings',
                    'receipt_approved',
                    'Your booking is confirmed',
                    "{$club->name} checked your {$wallet} receipt. See you on court.",
                    "/bookings/{$booking->id}",
                );
            } else {
                NotificationService::send(
                    (int) $booking->user_id,
                    'bookings',
                    'receipt_rejected',
                    'Send another receipt',
                    "{$club->name} couldn't accept your receipt: {$rejection} The court is held for 30 more minutes.",
                    "/bookings/{$booking->id}",
                );
            }
        }

        return $this->successResponse(
            data: Contract::bookingDetail($booking->fresh()),
            message: $action === 'approve' ? 'Receipt approved.' : 'Receipt rejected.'
        );
    }

    public function payAtCourt(Tenant $club, Booking $booking): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $booking->tenant_id)) {
            return $this->errorResponse('Booking not found in this club.', code: Response::HTTP_NOT_FOUND);
        }

        $booking->update(['payment_method' => 'pay_at_court', 'hold_expires_at' => null]);

        return $this->successResponse(
            data: Contract::bookingDetail($booking->fresh()),
            message: 'Player will pay at the court.'
        );
    }

    public function cancel(Request $request, Tenant $club, Booking $booking): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $booking->tenant_id)) {
            return $this->errorResponse('Booking not found in this club.', code: Response::HTTP_NOT_FOUND);
        }

        if ($booking->status === 'cancelled') {
            return $this->errorResponse('That booking is already cancelled.', code: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $from = $booking->status;

        $booking->statusHistories()->create([
            'changed_by' => $request->user()->id,
            'from_status' => $from,
            'to_status' => 'cancelled',
            'reason' => $data['reason'],
        ]);

        $booking->update(['status' => 'cancelled', 'hold_expires_at' => null]);

        return $this->successResponse(
            data: Contract::bookingDetail($booking->fresh()),
            message: 'Booking cancelled.'
        );
    }
}
