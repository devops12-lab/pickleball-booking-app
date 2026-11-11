<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesClub;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtBlockedSchedule;
use App\Models\Tenant;
use App\Services\BookingHoldService;
use App\Support\Compat\Contract;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * GET /api/v1/clubs/{club}/calendar?date=YYYY-MM-DD — one day of court
 * occupancy: open hours, the courts, every block, and the closures.
 */
class CalendarController extends Controller
{
    use ApiResponse, AuthorizesClub;

    public function __construct(private readonly BookingHoldService $holds) {}

    public function show(Request $request, Tenant $club): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $timezone = $club->venueProfile?->timezone ?: 'Asia/Manila';
        $day = CarbonImmutable::parse($validated['date'].' 00:00:00', $timezone);
        $dayEnd = $day->endOfDay();

        $this->holds->cancelExpired();

        $courts = $club->courts()->orderBy('id')->with(['operatingHours', 'priceRules'])->get();

        $bookings = Booking::query()
            ->where('tenant_id', $club->id)
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $day)
            ->with(['user', 'creator', 'payments', 'receipts.reviewer'])
            ->orderBy('starts_at')
            ->get();

        $closures = CourtBlockedSchedule::query()
            ->where('tenant_id', $club->id)
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $day)
            ->get();

        [$opensAt, $closesAt] = $this->openingWindow($courts, $day);

        $blocks = $bookings->map(fn (Booking $booking): array => [
            'reservation_id' => (string) $booking->id,
            'court_id' => (string) $booking->court_id,
            'reservation_type' => 'booking',
            'starts_at' => $booking->starts_at->toIso8601String(),
            'ends_at' => $booking->ends_at->toIso8601String(),
            'status' => match ($booking->status) {
                'confirmed' => 'confirmed',
                'completed' => 'completed',
                default => 'held',
            },
            'label' => (string) $booking->booking_reference,
            'booking' => Contract::booking($booking),
        ])->values()->all();

        return $this->successResponse(data: [
            'date' => $validated['date'],
            'opens_at' => $opensAt->toIso8601String(),
            'closes_at' => $closesAt->toIso8601String(),
            'courts' => $courts->map(fn (Court $court) => Contract::court($court))->all(),
            'blocks' => $blocks,
            'closures' => $closures->map(fn (CourtBlockedSchedule $closure): array => [
                'court_id' => (string) $closure->court_id,
                'starts_at' => $closure->starts_at->toIso8601String(),
                'ends_at' => $closure->ends_at->toIso8601String(),
                'reason' => (string) ($closure->reason ?? ''),
            ])->values()->all(),
        ]);
    }

    /**
     * Earliest opening and latest closing across the club's courts for that
     * weekday, defaulting to 06:00-22:00 when no court has hours stored.
     *
     * @param  Collection<int, Court>  $courts
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function openingWindow($courts, CarbonImmutable $day): array
    {
        $opens = [];
        $closes = [];

        foreach ($courts as $court) {
            $hours = collect(Contract::hours($court))->firstWhere('weekday', $day->dayOfWeek);

            if (! $hours || $hours['is_closed'] || ! $hours['opens_at'] || ! $hours['closes_at']) {
                continue;
            }

            $opens[] = $hours['opens_at'];
            $closes[] = $hours['closes_at'];
        }

        $open = $opens === [] ? '06:00' : min($opens);
        $close = $closes === [] ? '22:00' : max($closes);

        return [
            CarbonImmutable::parse($day->format('Y-m-d').' '.$open, $day->timezone),
            CarbonImmutable::parse($day->format('Y-m-d').' '.$close, $day->timezone),
        ];
    }
}
