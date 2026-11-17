<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Traits\ApiResponse;
use App\Models\Booking;
use App\Models\Court;
use App\Models\GuestBookingCode;
use App\Services\BookingAvailabilityService;
use App\Services\Mail\EmailSettingsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class GuestBookingController extends Controller
{
    use ApiResponse;

    private const CODE_TTL_MINUTES = 10;

    private const RESEND_SECONDS = 60;

    private const MAX_ATTEMPTS = 5;

    private const MAX_LOOKUP_FAILURES = 10;

    private const LOOKUP_LOCK_MINUTES = 15;

    public function __construct(
        private BookingAvailabilityService $availability,
        private EmailSettingsService $mail
    ) {}

    /**
     * Send a verification code to the guest's email.
     */
    public function requestCode(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name', ''));
        $email = strtolower(trim((string) $request->input('email', '')));
        $errors = [];

        if (mb_strlen($name) < 2) {
            $errors['name'][] = 'Enter your full name.';
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'Enter a valid email.';
        }

        if ($errors !== []) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: $errors,
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $recent = GuestBookingCode::where('email', $email)
            ->whereNull('used_at')
            ->where('last_sent_at', '>', now()->subSeconds(self::RESEND_SECONDS))
            ->exists();

        if ($recent) {
            return $this->errorResponse(
                message: 'A code was just sent. Wait a minute before requesting another.',
                code: Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $code = (string) random_int(100000, 999999);

        $row = GuestBookingCode::create([
            'email' => $email,
            'name' => $name,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'last_sent_at' => now(),
        ]);

        $this->mail->trySend(
            $email,
            'Your CourtFlex guest booking code',
            [
                "Your guest booking code is {$code}.",
                'It expires in 10 minutes.',
            ],
        );

        return $this->successResponse(data: [
            'verification_id' => 'gv'.$row->id,
            'email' => $email,
            'expires_at' => $row->expires_at->toIso8601String(),
        ], code: Response::HTTP_CREATED);
    }

    /**
     * Book a court after verifying the emailed code.
     */
    public function store(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name', ''));
        $email = strtolower(trim((string) $request->input('email', '')));
        $phone = preg_replace('/[\s-]/', '', (string) $request->input('phone', ''));
        $errors = [];

        if (mb_strlen($name) < 2) {
            $errors['name'][] = 'Enter your full name.';
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'Enter a valid email.';
        }

        if (preg_match('/^(\+?63|0)9\d{9}$/', (string) $phone) !== 1) {
            $errors['phone'][] = 'Enter a mobile number, like 09171234567.';
        }

        $courtId = $request->integer('court_id');
        if (! $courtId) {
            $errors['court_id'][] = 'Select a court.';
        }

        $startsAt = $request->input('starts_at');
        $endsAt = $request->input('ends_at');
        if (! $startsAt || ! $endsAt) {
            $errors['starts_at'][] = 'Choose a time slot.';
        }

        if ($errors !== []) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: $errors,
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Verify the emailed code
        $verification = (string) $request->input('verification_id', '');
        $row = preg_match('/^gv(\d+)$/', $verification, $matches) === 1
            ? GuestBookingCode::find((int) $matches[1])
            : null;

        if ($row === null || $row->email !== $email || $row->used_at !== null) {
            return $this->errorResponse(
                message: 'Invalid or expired verification. Request a new code.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($row->expires_at->isPast()) {
            return $this->errorResponse(
                message: 'That code has expired. Request a new one.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($row->attempts >= self::MAX_ATTEMPTS) {
            return $this->errorResponse(
                message: 'Too many wrong codes. Request a new one.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (! Hash::check(trim((string) $request->input('code', '')), $row->code_hash)) {
            $row->increment('attempts');

            return $this->errorResponse(
                message: 'Incorrect code. '.max(0, self::MAX_ATTEMPTS - $row->fresh()->attempts).' tries left.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $court = Court::find($courtId);
        if (! $court) {
            return $this->errorResponse('Court not found.', code: Response::HTTP_NOT_FOUND);
        }

        if (! $this->availability->isAvailable($court, $startsAt, $endsAt)) {
            return $this->errorResponse(
                'The selected time slot is not available.',
                code: Response::HTTP_CONFLICT
            );
        }

        $notes = trim((string) $request->input('notes', ''));
        $method = (string) $request->input('payment_method', 'pay_at_court');
        $hold = in_array($method, ['gcash', 'maya'], true);

        $booking = DB::transaction(function () use ($court, $startsAt, $endsAt, $name, $email, $phone, $notes, $method, $hold, $row) {
            if ($this->availability->hasConflictingBooking($court, Carbon::parse($startsAt), Carbon::parse($endsAt))) {
                return null;
            }

            $booking = Booking::create([
                'tenant_id' => $court->tenant_id,
                'court_id' => $court->id,
                'user_id' => null,
                'guest_name' => $name,
                'guest_email' => $email,
                'guest_phone' => $phone,
                'created_by' => null,
                'booking_reference' => strtoupper(Str::random(8)),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'total_amount' => 0,
                'status' => $hold ? 'pending' : 'confirmed',
                'source' => 'guest_web',
                'payment_method' => $method,
                'hold_expires_at' => $hold ? now()->addMinutes(30) : null,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            $booking->statusHistories()->create([
                'changed_by' => null,
                'from_status' => null,
                'to_status' => $booking->status,
                'reason' => 'Booked as a guest via public booking',
            ]);

            $row->update(['used_at' => now()]);

            return $booking;
        });

        if ($booking === null) {
            return $this->errorResponse(
                'That slot was just taken. Please choose another time.',
                code: Response::HTTP_CONFLICT
            );
        }

        return $this->successResponse(
            data: new BookingResource($booking->load('court')),
            message: 'Court booked.',
            code: Response::HTTP_CREATED,
        );
    }

    /**
     * Find a guest booking by reference code and email.
     */
    public function find(Request $request): JsonResponse
    {
        $result = $this->lookupBooking($request);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        [$booking] = $result;

        return $this->successResponse(
            data: new BookingResource($booking->load('court'))
        );
    }

    /**
     * Cancel a guest booking.
     */
    public function cancel(Request $request): JsonResponse
    {
        $result = $this->lookupBooking($request);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        [$booking] = $result;

        if (in_array($booking->status, ['cancelled', 'completed'], true)) {
            return $this->errorResponse(
                "This booking is already {$booking->status}.",
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $oldStatus = $booking->status;

        DB::transaction(function () use ($booking, $oldStatus): void {
            $booking->update(['status' => 'cancelled']);

            $booking->statusHistories()->create([
                'changed_by' => null,
                'from_status' => $oldStatus,
                'to_status' => 'cancelled',
                'reason' => 'Cancelled by the guest',
            ]);
        });

        return $this->successResponse(
            data: new BookingResource($booking->fresh()),
            message: 'Booking cancelled.'
        );
    }

    /**
     * @return array{0: Booking, 1: string}|JsonResponse
     */
    private function lookupBooking(Request $request): array|JsonResponse
    {
        $code = strtoupper(trim((string) $request->input('booking_code', '')));
        $email = strtolower(trim((string) $request->input('email', '')));
        $errors = [];

        if ($code === '') {
            $errors['booking_code'][] = 'Enter your booking code.';
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'Enter the email you booked with.';
        }

        if ($errors !== []) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: $errors,
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $key = 'guest_lookup:'.$code;
        $lock = Cache::get($key, ['count' => 0, 'until' => 0]);

        if ($lock['count'] >= self::MAX_LOOKUP_FAILURES && now()->getTimestamp() < $lock['until']) {
            return $this->errorResponse(
                message: 'Too many tries for this code. Wait 15 minutes.',
                code: Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $booking = Booking::where('booking_reference', $code)
            ->where('guest_email', $email)
            ->whereNull('user_id')
            ->first();

        if ($booking === null) {
            $lock['count']++;

            if ($lock['count'] >= self::MAX_LOOKUP_FAILURES) {
                $lock['until'] = now()->addMinutes(self::LOOKUP_LOCK_MINUTES)->getTimestamp();
            }

            Cache::put($key, $lock, now()->addHour());

            return $this->errorResponse(
                message: 'No booking matches that code and email.',
                code: Response::HTTP_NOT_FOUND,
            );
        }

        Cache::forget($key);

        return [$booking, $email];
    }
}
