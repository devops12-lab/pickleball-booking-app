<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Booking;
use App\Models\BookingReceipt;
use App\Models\Court;
use App\Models\Payment;
use App\Models\PlayerProfile;
use App\Models\Tenant;
use App\Services\BookingAvailabilityService;
use App\Services\BookingHoldService;
use App\Support\Compat\Contract;
use App\Support\Compat\PlayerBookingView;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A player booking a court (§9.4): the price first, then the booking, the
 * receipt, the list, and cancelling. One implementation for the whole chain,
 * with the venue's own timezone deciding what "today" and "17:00" mean.
 */
class PlayerBookingController extends Controller
{
    use ApiResponse;

    /** The durations the app offers. */
    private const DURATIONS = [60, 90, 120, 180];

    /** A GCash/Maya booking holds the court this long while the receipt is sent. */
    private const HOLD_MINUTES = 30;

    public function __construct(
        private readonly BookingAvailabilityService $availability,
        private readonly BookingHoldService $holds,
    ) {}

    public function quote(Request $request): JsonResponse
    {
        $checked = $this->checkRequest($request, $request->user()?->id);

        if (isset($checked['error'])) {
            return $checked['error'];
        }

        [$tenant, $court, $club, $startsLocal, $minutes] = $checked;

        $court->load(['operatingHours', 'priceRules']);

        return $this->successResponse(data: PlayerBookingView::quote(
            $tenant,
            $court,
            $startsLocal,
            $minutes,
            $club['settings'],
        ));
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnreadyPlayer($request)) {
            return $denied;
        }

        $checked = $this->checkRequest($request, $request->user()->id);

        if (isset($checked['error'])) {
            return $checked['error'];
        }

        [$tenant, $court, $club, $startsLocal, $minutes, $endsLocal] = $checked;
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

        [$lines, $total] = PlayerBookingView::lines($court, $startsLocal, $minutes);
        $expected = (string) $request->input('expected_total', '');

        if ($expected !== $total) {
            return response()->json([
                'message' => "The price for this time is now ₱{$total}. Check it and confirm again.",
                'code' => 'price_changed',
                'details' => ['total' => $total],
            ], Response::HTTP_CONFLICT);
        }

        $hold = in_array($method, ['gcash', 'maya'], true);
        $startsUtc = $startsLocal->setTimezone('UTC');

        $booking = DB::transaction(function () use ($request, $tenant, $court, $startsLocal, $endsLocal, $notes, $method, $hold, $total) {
            // null, not the clashing booking: returning that row would hand one
            // player another player's booking.
            if ($this->overlap($court, $startsLocal, $endsLocal) !== null) {
                return null;
            }

            $booking = Booking::create([
                'tenant_id' => $tenant->id,
                'court_id' => $court->id,
                'user_id' => $request->user()->id,
                'created_by' => $request->user()->id,
                'booking_reference' => $this->reference(),
                'starts_at' => $startsLocal->setTimezone('UTC')->toDateTimeString(),
                'ends_at' => $endsLocal->setTimezone('UTC')->toDateTimeString(),
                'total_amount' => $total,
                'status' => $hold ? 'pending' : 'confirmed',
                'source' => 'player_app',
                'payment_method' => $method,
                'hold_expires_at' => $hold ? now()->addMinutes(self::HOLD_MINUTES) : null,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            $booking->statusHistories()->create([
                'changed_by' => $request->user()->id,
                'from_status' => null,
                'to_status' => $booking->status,
                'reason' => $method === 'pay_at_court'
                    ? 'Booked in the app, paying at the court'
                    : 'Held for '.($method === 'gcash' ? 'GCash' : 'Maya').' payment',
            ]);

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
            data: PlayerBookingView::view($booking->fresh(), (string) $request->user()->id),
            message: 'Court booked.',
            code: Response::HTTP_CREATED,
        );
    }

    public function receipt(Request $request, Booking $booking): JsonResponse
    {
        if ($denied = $this->denyUnreadyPlayer($request)) {
            return $denied;
        }

        if ((int) $booking->user_id !== (int) $request->user()->id) {
            return $this->errorResponse(message: 'Booking not found.', code: Response::HTTP_NOT_FOUND);
        }

        return $this->applyReceipt($request, $booking, $request->user()->id, (string) $request->user()->id);
    }

    /**
     * The receipt rules, shared with guest bookings (no account, so no user id).
     */
    protected function applyReceipt(Request $request, Booking $booking, ?int $changedBy, string $viewerId): JsonResponse
    {
        $this->cancelExpiredHolds();
        $booking->refresh();

        if (! in_array($booking->payment_method, ['gcash', 'maya'], true)) {
            return $this->errorResponse(
                message: "This booking is paid at the court, so there's no receipt to send.",
                code: Response::HTTP_CONFLICT,
            );
        }

        if (in_array($booking->status, ['cancelled', 'expired'], true)) {
            return $this->errorResponse(
                message: 'This booking was cancelled, so the court was freed. Book again.',
                code: Response::HTTP_CONFLICT,
            );
        }

        if ($booking->status !== 'pending') {
            return $this->errorResponse(
                message: "This booking isn't waiting for a payment.",
                code: Response::HTTP_CONFLICT,
            );
        }

        $existing = $booking->receipts()->latest('id')->first();

        if ($existing !== null && $existing->status === 'in_review') {
            return $this->errorResponse(
                message: "Your receipt is already with the venue. You'll see here when it's checked.",
                code: Response::HTTP_CONFLICT,
            );
        }

        $image = (string) $request->input('receipt', '');
        $errors = [];

        if (preg_match('#^data:image/(png|jpeg|webp);base64,#', $image) !== 1) {
            $errors['receipt'][] = 'Upload a screenshot of the receipt (PNG, JPG or WebP).';
        } elseif (strlen($image) > 7_000_000) {
            $errors['receipt'][] = 'That image is over 5 MB. Use a smaller screenshot.';
        }

        $reference = preg_replace('/\s/', '', (string) $request->input('reference_number', ''));

        if (preg_match('/^[A-Za-z0-9-]{6,30}$/', (string) $reference) !== 1) {
            $errors['reference_number'][] = 'Enter the reference number from the receipt: letters and numbers, at least 6.';
        } elseif ($this->referenceTaken($booking, (string) $reference)) {
            $errors['reference_number'][] = 'This reference number is already on another booking.';
        }

        if ($errors !== []) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: $errors,
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        BookingReceipt::create([
            'booking_id' => $booking->id,
            'image_url' => $image,
            'reference_number' => $reference,
            'status' => 'in_review',
        ]);

        $booking->statusHistories()->create([
            'changed_by' => $changedBy,
            'from_status' => 'pending',
            'to_status' => 'pending',
            'reason' => 'Sent a '.($booking->payment_method === 'gcash' ? 'GCash' : 'Maya')." receipt, ref {$reference}",
        ]);

        return $this->successResponse(data: PlayerBookingView::view($booking->fresh(), $viewerId));
    }

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnreadyPlayer($request)) {
            return $denied;
        }
        $this->cancelExpiredHolds();

        $tab = (string) $request->query('tab', 'upcoming');

        if (! in_array($tab, ['upcoming', 'past', 'cancelled'], true)) {
            return $this->fieldError('tab', 'Choose upcoming, past or cancelled.');
        }

        $bookings = Booking::query()
            ->where('user_id', $request->user()->id)
            ->with(['court', 'tenant.venueProfile', 'tenant.subscription', 'payments', 'receipts.reviewer', 'statusHistories.user'])
            ->get()
            ->filter(fn (Booking $booking) => $this->tabOf($booking) === $tab)
            ->sortBy(fn (Booking $booking) => $booking->starts_at->getTimestamp(), SORT_REGULAR, $tab !== 'upcoming')
            ->values();

        return $this->successResponse(
            data: $bookings->map(fn (Booking $booking) => PlayerBookingView::view($booking, (string) $request->user()->id))->all()
        );
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        if ($denied = $this->denyUnreadyPlayer($request)) {
            return $denied;
        }

        if ((int) $booking->user_id !== (int) $request->user()->id) {
            return $this->errorResponse(message: 'Booking not found.', code: Response::HTTP_NOT_FOUND);
        }

        $this->cancelExpiredHolds();

        return $this->successResponse(
            data: PlayerBookingView::view($booking->refresh(), (string) $request->user()->id)
        );
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        if ($denied = $this->denyUnreadyPlayer($request)) {
            return $denied;
        }

        if ((int) $booking->user_id !== (int) $request->user()->id) {
            return $this->errorResponse(message: 'Booking not found.', code: Response::HTTP_NOT_FOUND);
        }

        return $this->applyCancel($request, $booking, $request->user()->id, (string) $request->user()->id, 'Cancelled by the player in the app');
    }

    /**
     * The cancellation rules, shared with guest bookings.
     */
    protected function applyCancel(Request $request, Booking $booking, ?int $changedBy, string $viewerId, string $defaultReason): JsonResponse
    {
        $this->cancelExpiredHolds();
        $booking->refresh();

        $reason = trim((string) $request->input('reason', ''));

        if (mb_strlen($reason) > 300) {
            return $this->fieldError('reason', 'Keep it under 300 characters.');
        }

        if ($booking->status === 'cancelled') {
            return $this->errorResponse(message: 'This booking is already cancelled.', code: Response::HTTP_CONFLICT);
        }

        if (! in_array($booking->status, ['pending', 'confirmed'], true) || $booking->starts_at->isPast()) {
            return $this->errorResponse(
                message: "This booking has already started, so it can't be cancelled.",
                code: Response::HTTP_CONFLICT,
            );
        }

        $view = PlayerBookingView::view($booking, $viewerId);

        if (! $view['can_cancel']) {
            return $this->errorResponse(
                message: (string) ($view['cancel_blocked_reason'] ?? "This booking can't be cancelled."),
                code: Response::HTTP_CONFLICT,
            );
        }

        $from = $booking->status;

        $booking->statusHistories()->create([
            'changed_by' => $changedBy,
            'from_status' => $from,
            'to_status' => 'cancelled',
            'reason' => $reason !== '' ? $reason : $defaultReason,
        ]);

        $booking->update(['status' => 'cancelled', 'hold_expires_at' => null]);

        return $this->successResponse(
            data: PlayerBookingView::view($booking->fresh(), $viewerId),
            message: 'Booking cancelled.'
        );
    }

    /**
     * A venue's whole day for the booking grid. Open to visitors.
     */
    public function day(Request $request, string $slug): JsonResponse
    {
        $tenant = Tenant::query()
            ->where('slug', $slug)
            ->with(['venueProfile', 'subscription', 'courts.operatingHours', 'courts.priceRules'])
            ->first();

        if ($tenant === null || $tenant->venueProfile === null) {
            return $this->errorResponse(message: 'Venue not found.', code: Response::HTTP_NOT_FOUND);
        }

        $club = Contract::club($tenant);
        $profile = $tenant->venueProfile;

        if ($club['status'] !== 'active' || $club['read_only'] || $profile->latitude === null) {
            return $this->errorResponse(message: 'Venue not found.', code: Response::HTTP_NOT_FOUND);
        }

        $timezone = $club['timezone'];
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $date = (string) $request->query('date', $today->toDateString());

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return $this->fieldError('date', 'Choose a date.');
        }

        $window = (int) ($club['settings']['booking_window_days'] ?? 30);

        if ($date < $today->toDateString()) {
            return $this->fieldError('date', 'That date has passed.');
        }

        if ($date > $today->addDays($window)->toDateString()) {
            return $this->fieldError('date', "{$tenant->name} takes bookings up to {$window} days ahead.");
        }

        $this->cancelExpiredHolds();

        $courts = $tenant->courts
            ->filter(fn (Court $court) => Contract::court($court)['status'] === 'active')
            ->values();

        $weekday = CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone)->dayOfWeek;
        $spans = [];

        foreach ($courts as $court) {
            $hours = collect(Contract::hours($court))->firstWhere('weekday', $weekday);

            if ($hours && ! $hours['is_closed'] && $hours['opens_at'] && $hours['closes_at']) {
                $spans[] = $hours;
            }
        }

        $rowStarts = [];

        if ($spans !== []) {
            $opens = min(array_column($spans, 'opens_at'));
            $closes = max(array_column($spans, 'closes_at'));
            $cursor = CarbonImmutable::parse("{$date} {$opens}", $timezone);
            $close = CarbonImmutable::parse("{$date} {$closes}", $timezone);

            while ($cursor->addHour()->lessThanOrEqualTo($close)) {
                $rowStarts[] = $cursor;
                $cursor = $cursor->addHour();
            }
        }

        $now = CarbonImmutable::now($timezone);

        return $this->successResponse(data: [
            'venue' => [
                'id' => (string) $tenant->id,
                'slug' => (string) $tenant->slug,
                'name' => (string) $tenant->name,
                'address' => $club['address'],
                'city' => $club['city'],
                'province' => $club['province'],
                'latitude' => (float) $profile->latitude,
                'longitude' => (float) $profile->longitude,
                'cover_url' => $club['cover_url'],
                'contact_phone' => $club['contact_phone'],
                'bookable' => (bool) ($club['settings']['allow_online_booking'] ?? true),
                'booking_window_days' => $window,
                'price_from' => Contract::money($courts->min(fn (Court $court) => (float) $court->hourly_rate)),
                'cancellation_policy' => (string) ($club['settings']['cancellation_policy'] ?? ''),
                'payments' => $club['settings']['booking_payments'],
                'guest_booking' => (bool) ($club['settings']['allow_guest_booking'] ?? false),
            ],
            'date' => $date,
            'rows' => array_map(fn (CarbonImmutable $start) => $start->format('H:i'), $rowStarts),
            'courts' => $courts->map(function (Court $court) use ($rowStarts, $weekday, $now, $timezone): array {
                $hours = collect(Contract::hours($court))->firstWhere('weekday', $weekday);

                $cells = array_map(function (CarbonImmutable $start) use ($court, $hours, $now, $timezone): ?array {
                    $end = $start->addHour();

                    if (! $hours || $hours['is_closed'] || ! $hours['opens_at'] || ! $hours['closes_at']) {
                        return null;
                    }

                    if ($start->format('H:i') < $hours['opens_at'] || $end->format('H:i') > $hours['closes_at']) {
                        return null;
                    }

                    $available = $this->availability->isAvailable(
                        $court,
                        $start->toDateTimeString(),
                        $end->toDateTimeString(),
                        null,
                        $timezone,
                    );

                    $state = 'available';

                    if ($start->lessThanOrEqualTo($now)) {
                        $state = 'past';
                    } elseif (! $available) {
                        $state = $this->availability->isBlocked(
                            $court,
                            $start->setTimezone('UTC'),
                            $end->setTimezone('UTC'),
                        ) ? 'closed' : 'booked';
                    }

                    $price = Contract::priceForSlot($court, $start, 60);

                    return [
                        'starts_at' => $start->toIso8601String(),
                        'ends_at' => $end->toIso8601String(),
                        'state' => $state,
                        'price' => Contract::money($price),
                        'special_rate' => abs($price - (float) $court->hourly_rate) > 0.001,
                    ];
                }, $rowStarts);

                return [
                    'id' => (string) $court->id,
                    'name' => (string) $court->name,
                    'indoor_outdoor' => $court->court_type === 'outdoor' ? 'outdoor' : 'indoor',
                    'surface_type' => (string) ($court->surface_type ?? ''),
                    'base_price' => Contract::money($court->hourly_rate),
                    'cells' => $cells,
                ];
            })->all(),
        ]);
    }

    // ── Shared checks ────────────────────────────────────────

    /**
     * Validate a requested slot and return [tenant, court, club, local start, minutes, local end].
     *
     * @return array<int, mixed>|array{error: JsonResponse}
     */
    protected function checkRequest(Request $request, ?int $playerId): array
    {
        $this->cancelExpiredHolds();

        $errors = [];
        $court = Court::query()->with(['tenant.venueProfile', 'tenant.subscription', 'operatingHours', 'priceRules'])
            ->find($request->input('court_id') ?? $request->query('court_id'));

        $tenant = $court?->tenant;
        $club = $tenant ? Contract::club($tenant) : null;

        if ($court === null || $tenant === null || $club === null
            || $club['status'] !== 'active' || $club['read_only']
            || $tenant->venueProfile?->latitude === null) {
            return ['error' => $this->fieldError('court_id', "This court isn't taking bookings.")];
        }

        if (! ($club['settings']['allow_online_booking'] ?? true)) {
            return ['error' => $this->fieldError('court_id', "{$tenant->name} doesn't take bookings in the app yet. Call them to book.")];
        }

        $minutes = (int) ($request->input('duration_minutes') ?? $request->query('duration_minutes') ?? 60);

        if (! in_array($minutes, self::DURATIONS, true)) {
            $errors['duration_minutes'][] = 'Choose 1, 1.5 or 2 hours.';
        }

        $raw = (string) ($request->input('starts_at') ?? $request->query('starts_at') ?? '');

        try {
            $start = CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return ['error' => $this->fieldError('starts_at', 'Choose a start time.')];
        }

        $timezone = $club['timezone'];
        $startLocal = $start->setTimezone($timezone);
        $minutes = $minutes > 0 ? $minutes : 60;
        $endLocal = $startLocal->addMinutes($minutes);
        $endUtc = $endLocal->setTimezone('UTC');

        if (! in_array($startLocal->format('i'), ['00', '30'], true) || $startLocal->format('s') !== '00') {
            $errors['starts_at'][] = 'Bookings start on the hour or half hour.';
        } else {
            $today = CarbonImmutable::now($timezone)->startOfDay();
            $window = (int) ($club['settings']['booking_window_days'] ?? 30);

            if ($startLocal->toDateString() > $today->addDays($window)->toDateString()) {
                $errors['starts_at'][] = "{$tenant->name} takes bookings up to {$window} days ahead.";
            } elseif ($startLocal->isPast()) {
                $errors['starts_at'][] = 'That time has already passed. Choose a later slot.';
            } else {
                // Here only the clock and closures are checked. A clash is a 409 when
                // the booking is written, so the app can offer free alternatives
                // instead of showing a field error.
                $startCarbon = Carbon::parse($startLocal->toDateTimeString(), $timezone);
                $endCarbon = Carbon::parse($endLocal->toDateTimeString(), $timezone);

                if (! $this->availability->isWithinOperatingHours($court, $startCarbon, $endCarbon)) {
                    $errors['starts_at'][] = $this->closedReason($court, $startLocal);
                } elseif ($this->availability->isBlocked(
                    $court,
                    $startLocal->setTimezone('UTC'),
                    $endLocal->setTimezone('UTC'),
                )) {
                    $errors['starts_at'][] = 'The venue has closed the court at that time.';
                }
            }
        }

        if ($playerId !== null && $errors === []) {
            $clash = Booking::query()
                ->where('user_id', $playerId)
                ->whereIn('status', ['pending', 'confirmed'])
                ->where('starts_at', '<', $endUtc->toDateTimeString())
                ->where('ends_at', '>', $startLocal->setTimezone('UTC')->toDateTimeString())
                ->with('court')
                ->first();

            if ($clash !== null) {
                $errors['starts_at'][] = 'You already have '.($clash->court?->name ?? 'a court')
                    .' booked from '.CarbonImmutable::parse($clash->starts_at)->setTimezone($timezone)->format('H:i')
                    .' to '.CarbonImmutable::parse($clash->ends_at)->setTimezone($timezone)->format('H:i').'.';
            }
        }

        if ($errors !== []) {
            return ['error' => $this->errorResponse(
                message: 'The given data was invalid.',
                errors: $errors,
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            )];
        }

        return [$tenant, $court, $club, $startLocal, $minutes, $endLocal];
    }

    private function closedReason(Court $court, CarbonImmutable $start): string
    {
        $hours = collect(Contract::hours($court))->firstWhere('weekday', $start->dayOfWeek);

        if (! $hours || $hours['is_closed'] || ! $hours['opens_at'] || ! $hours['closes_at']) {
            return 'The court is closed that day.';
        }

        return "That time is outside the court's opening hours.";
    }

    private function denyUnreadyPlayer(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at === null) {
            return $this->errorResponse(
                message: 'Verify your email before you book a court.',
                code: Response::HTTP_FORBIDDEN,
            );
        }

        if (! PlayerProfile::query()->where('user_id', $user->id)->exists()) {
            return $this->errorResponse(
                message: 'Finish setting up your player profile before you book a court.',
                code: Response::HTTP_FORBIDDEN,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    protected function payMethods(array $settings): array
    {
        $payments = is_array($settings['booking_payments'] ?? null) ? $settings['booking_payments'] : [];
        $methods = [];

        if (($payments['pay_at_court'] ?? false) && ! ($payments['require_prepayment'] ?? false)) {
            $methods[] = 'pay_at_court';
        }

        if (! empty($payments['gcash'])) {
            $methods[] = 'gcash';
        }

        if (! empty($payments['maya'])) {
            $methods[] = 'maya';
        }

        return $methods;
    }

    protected function overlap(Court $court, CarbonImmutable $start, CarbonImmutable $end): ?Booking
    {
        $found = Booking::query()
            ->where('court_id', $court->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('starts_at', '<', $end->setTimezone('UTC')->toDateTimeString())
            ->where('ends_at', '>', $start->setTimezone('UTC')->toDateTimeString())
            ->first();

        return $found;
    }

    /**
     * Free alternatives: the same time on the venue's other courts, then the
     * nearest times on this one.
     *
     * @return list<array<string, mixed>>
     */
    protected function alternatives(Court $court, CarbonImmutable $start, int $minutes): array
    {
        $options = [];
        $end = $start->addMinutes($minutes);

        $others = Court::query()
            ->with(['operatingHours', 'priceRules'])
            ->where('tenant_id', $court->tenant_id)
            ->where('id', '!=', $court->id)
            ->get()
            ->filter(fn (Court $other) => Contract::court($other)['status'] === 'active')
            ->filter(fn (Court $other) => $this->availability->isAvailable(
                $other,
                $start->toDateTimeString(),
                $end->toDateTimeString(),
                null,
                $start->getTimezone()->getName(),
            ))
            ->take(3);

        foreach ($others as $other) {
            $options[] = [
                'court_id' => (string) $other->id,
                'court_name' => (string) $other->name,
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
                'total' => Contract::money(Contract::priceForSlot($other, $start, $minutes)),
            ];
        }

        $hours = collect(Contract::hours($court))->firstWhere('weekday', $start->dayOfWeek);

        if ($hours && ! $hours['is_closed'] && $hours['opens_at'] && $hours['closes_at']) {
            $day = $start->toDateString();
            $opens = CarbonImmutable::parse("{$day} {$hours['opens_at']}", $start->getTimezone());
            $closes = CarbonImmutable::parse("{$day} {$hours['closes_at']}", $start->getTimezone());
            $nearby = [];

            for ($cursor = $opens; $cursor->addMinutes($minutes)->lessThanOrEqualTo($closes); $cursor = $cursor->addMinutes(30)) {
                if ($cursor->equalTo($start)) {
                    continue;
                }

                if (! $this->availability->isAvailable(
                    $court,
                    $cursor->toDateTimeString(),
                    $cursor->addMinutes($minutes)->toDateTimeString(),
                    null,
                    $start->getTimezone()->getName(),
                )) {
                    continue;
                }

                $nearby[] = [
                    'court_id' => (string) $court->id,
                    'court_name' => (string) $court->name,
                    'starts_at' => $cursor->toIso8601String(),
                    'ends_at' => $cursor->addMinutes($minutes)->toIso8601String(),
                    'total' => Contract::money(Contract::priceForSlot($court, $cursor, $minutes)),
                    'distance' => abs($cursor->getTimestamp() - $start->getTimestamp()),
                ];
            }

            usort($nearby, fn (array $a, array $b) => $a['distance'] <=> $b['distance']);

            foreach (array_slice($nearby, 0, 3) as $option) {
                unset($option['distance']);
                $options[] = $option;
            }
        }

        return $options;
    }

    private function referenceTaken(Booking $booking, string $reference): bool
    {
        $onReceipts = BookingReceipt::query()
            ->where('booking_id', '!=', $booking->id)
            ->where('status', '!=', 'rejected')
            ->whereRaw('LOWER(reference_number) = ?', [strtolower($reference)])
            ->whereHas('booking', fn ($query) => $query->where('tenant_id', $booking->tenant_id))
            ->exists();

        if ($onReceipts) {
            return true;
        }

        return Payment::query()
            ->whereRaw('LOWER(transaction_reference) = ?', [strtolower($reference)])
            ->whereHas('booking', fn ($query) => $query->where('tenant_id', $booking->tenant_id))
            ->exists();
    }

    private function tabOf(Booking $booking): string
    {
        if (in_array($booking->status, ['cancelled', 'expired'], true)) {
            return 'cancelled';
        }

        if (in_array($booking->status, ['completed', 'no_show'], true)) {
            return 'past';
        }

        return $booking->ends_at->isPast() ? 'past' : 'upcoming';
    }

    protected function cancelExpiredHolds(): void
    {
        $this->holds->cancelExpired();
    }

    protected function reference(): string
    {
        do {
            $reference = 'BK-'.strtoupper(Str::random(8));
        } while (Booking::query()->where('booking_reference', $reference)->exists());

        return $reference;
    }

    protected function fieldError(string $field, string $message): JsonResponse
    {
        return $this->errorResponse(
            message: 'The given data was invalid.',
            errors: [$field => [$message]],
            code: Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
