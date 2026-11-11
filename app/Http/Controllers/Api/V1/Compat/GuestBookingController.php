<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Models\Booking;
use App\Models\GuestBookingCode;
use App\Services\BookingAvailabilityService;
use App\Services\BookingHoldService;
use App\Services\Mail\EmailSettingsService;
use App\Support\Compat\PlayerBookingView;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * Booking without an account (§9.5): a code proves the email, then the booking,
 * receipt and cancellation use the same rules as a signed-in player's.
 */
class GuestBookingController extends PlayerBookingController
{
    private const CODE_TTL_MINUTES = 10;

    private const RESEND_SECONDS = 60;

    private const MAX_ATTEMPTS = 5;

    private const MAX_LOOKUP_FAILURES = 10;

    private const LOOKUP_LOCK_MINUTES = 15;

    public function __construct(
        BookingAvailabilityService $availability,
        BookingHoldService $holds,
        private readonly EmailSettingsService $mail,
    ) {
        parent::__construct($availability, $holds);
    }

    /**
     * Send a six-digit code to the guest's email.
     */
    public function code(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name', ''));
        $email = strtolower(trim((string) $request->input('email', '')));
        $errors = [];

        if (mb_strlen($name) < 2) {
            $errors['name'][] = 'Enter your full name.';
        }

        if (! $this->validEmail($email)) {
            $errors['email'][] = 'Enter a valid email. The code goes there.';
        }

        if ($errors !== []) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: $errors,
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $recent = GuestBookingCode::query()
            ->where('email', $email)
            ->whereNull('used_at')
            ->where('last_sent_at', '>', now()->subSeconds(self::RESEND_SECONDS))
            ->exists();

        if ($recent) {
            return $this->errorResponse(
                message: 'A code was just sent. Wait a minute before asking for another.',
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
            'resend_after' => $row->last_sent_at->addSeconds(self::RESEND_SECONDS)->toIso8601String(),
        ], code: Response::HTTP_CREATED);
    }

    /**
     * Book the court once the emailed code checks out.
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

        if (! $this->validEmail($email)) {
            $errors['email'][] = 'Enter a valid email.';
        }

        if (preg_match('/^(\+?63|0)9\d{9}$/', (string) $phone) !== 1) {
            $errors['phone'][] = 'Enter a mobile number, like 09171234567.';
        }

        if ($errors !== []) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: $errors,
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $verification = (string) $request->input('verification_id', '');
        $row = preg_match('/^gv(\d+)$/', $verification, $matches) === 1
            ? GuestBookingCode::find((int) $matches[1])
            : null;

        if ($row === null || $row->email !== $email || $row->used_at !== null) {
            return $this->fieldError('code', "Ask for a new code: this one isn't for this email.");
        }

        if ($row->expires_at->isPast()) {
            return $this->fieldError('code', 'That code has expired. Ask for a new one.');
        }

        if ($row->attempts >= self::MAX_ATTEMPTS) {
            return $this->fieldError('code', 'Too many wrong codes. Ask for a new one.');
        }

        if (! Hash::check(trim((string) $request->input('code', '')), $row->code_hash)) {
            $row->increment('attempts');
            $row->refresh();

            return $this->fieldError(
                'code',
                "That code doesn't match. ".max(0, self::MAX_ATTEMPTS - $row->attempts).' tries left.',
            );
        }

        $checked = $this->checkRequest($request, null);

        if (isset($checked['error'])) {
            return $checked['error'];
        }

        [$tenant, $court, $club, $startsLocal, $minutes, $endsLocal] = $checked;
        $timezone = $club['timezone'];

        // A guest has no account, so their email stands in for one on a clash.
        $clash = Booking::query()
            ->where('guest_email', $email)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('starts_at', '<', $endsLocal->setTimezone('UTC')->toDateTimeString())
            ->where('ends_at', '>', $startsLocal->setTimezone('UTC')->toDateTimeString())
            ->with('court')
            ->first();

        if ($clash !== null) {
            return $this->fieldError(
                'starts_at',
                'You already have '.($clash->court?->name ?? 'a court')
                .' booked from '.CarbonImmutable::parse($clash->starts_at)->setTimezone($timezone)->format('H:i')
                .' to '.CarbonImmutable::parse($clash->ends_at)->setTimezone($timezone)->format('H:i').'.',
            );
        }

        if (! ($club['settings']['allow_guest_booking'] ?? false)) {
            return $this->errorResponse(
                message: "{$tenant->name} asks everyone to sign in to book. Create a free account or sign in.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        $notes = trim((string) $request->input('notes', ''));

        if (mb_strlen($notes) > 300) {
            return $this->fieldError('notes', 'Keep notes under 300 characters.');
        }

        $methods = $this->payMethods($club['settings']);
        $method = (string) $request->input('payment_method', '');

        if (! in_array($method, $methods, true)) {
            return $this->fieldError(
                'payment_method',
                $methods === []
                    ? "{$tenant->name} hasn't set up payments in the app yet."
                    : "Choose how you'll pay.",
            );
        }

        [, $total] = PlayerBookingView::lines($court, $startsLocal, $minutes);

        if ((string) $request->input('expected_total', '') !== $total) {
            return response()->json([
                'message' => "The price for this time is now ₱{$total}. Check it and confirm again.",
                'code' => 'price_changed',
                'details' => ['total' => $total],
            ], Response::HTTP_CONFLICT);
        }

        $hold = in_array($method, ['gcash', 'maya'], true);

        $booking = DB::transaction(function () use ($tenant, $court, $startsLocal, $endsLocal, $notes, $method, $hold, $total, $email, $name, $phone, $row) {
            if ($this->overlap($court, $startsLocal, $endsLocal) !== null) {
                return null;
            }

            $booking = Booking::create([
                'tenant_id' => $tenant->id,
                'court_id' => $court->id,
                'user_id' => null,
                'guest_name' => $name,
                'guest_phone' => $phone,
                'guest_email' => $email,
                'created_by' => null,
                'booking_reference' => $this->reference(),
                'starts_at' => $startsLocal->setTimezone('UTC')->toDateTimeString(),
                'ends_at' => $endsLocal->setTimezone('UTC')->toDateTimeString(),
                'total_amount' => $total,
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
                'reason' => $method === 'pay_at_court'
                    ? 'Booked as a guest, paying at the court'
                    : 'Booked as a guest, held for '.($method === 'gcash' ? 'GCash' : 'Maya').' payment',
            ]);

            $row->update(['used_at' => now()]);

            return $booking;
        });

        if ($booking === null) {
            return response()->json([
                'message' => "{$court->name} was just booked for that time. Choose another slot.",
                'code' => 'slot_taken',
                'details' => ['alternatives' => $this->alternatives($court, $startsLocal, $minutes)],
            ], Response::HTTP_CONFLICT);
        }

        return $this->successResponse(
            data: PlayerBookingView::view($booking->fresh(), 'guest:'.$email),
            message: 'Court booked.',
            code: Response::HTTP_CREATED,
        );
    }

    /**
     * Find my booking: the code and the email it was made with.
     */
    public function find(Request $request): JsonResponse
    {
        $found = $this->lookup($request);

        if ($found instanceof JsonResponse) {
            return $found;
        }

        [$booking, $email] = $found;
        $this->expireHolds();

        return $this->successResponse(
            data: PlayerBookingView::view($booking->refresh(), 'guest:'.$email)
        );
    }

    public function sendReceipt(Request $request): JsonResponse
    {
        $found = $this->lookup($request);

        if ($found instanceof JsonResponse) {
            return $found;
        }

        [$booking, $email] = $found;

        return $this->applyReceipt($request, $booking, null, 'guest:'.$email);
    }

    public function cancelBooking(Request $request): JsonResponse
    {
        $found = $this->lookup($request);

        if ($found instanceof JsonResponse) {
            return $found;
        }

        [$booking, $email] = $found;

        return $this->applyCancel($request, $booking, null, 'guest:'.$email, 'Cancelled by the guest');
    }

    /**
     * The email and code are the whole credential: ten wrong emails per code
     * locks it for fifteen minutes.
     *
     * @return array{0: Booking, 1: string}|JsonResponse
     */
    private function lookup(Request $request): array|JsonResponse
    {
        $code = strtoupper(trim((string) $request->input('booking_code', '')));
        $email = strtolower(trim((string) $request->input('email', '')));
        $errors = [];

        if ($code === '') {
            $errors['booking_code'][] = 'Enter your booking code.';
        }

        if (! $this->validEmail($email)) {
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
                message: 'Too many tries for this code. Wait 15 minutes, or call the venue.',
                code: Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $this->expireHolds();

        $booking = Booking::query()
            ->where('booking_reference', $code)
            ->where('guest_email', $email)
            ->where('source', 'guest_web')
            ->first();

        if ($booking === null) {
            $lock['count']++;

            if ($lock['count'] >= self::MAX_LOOKUP_FAILURES) {
                $lock['until'] = now()->addMinutes(self::LOOKUP_LOCK_MINUTES)->getTimestamp();
            }

            Cache::put($key, $lock, now()->addHour());

            return $this->errorResponse(
                message: 'No booking matches that code and email. Check both, or call the venue.',
                code: Response::HTTP_NOT_FOUND,
            );
        }

        Cache::forget($key);

        return [$booking, $email];
    }

    private function validEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
