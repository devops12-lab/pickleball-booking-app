<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesClub;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Booking;
use App\Models\Court;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Compat\Contract;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/clubs/{club}/reports/daily?date=YYYY-MM-DD — the day's takings.
 */
class ReportController extends Controller
{
    use ApiResponse, AuthorizesClub;

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

        $bookings = Booking::query()
            ->where('tenant_id', $club->id)
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $day)
            ->with('payments')
            ->get();

        $payments = Payment::query()
            ->where('tenant_id', $club->id)
            ->whereIn('status', ['verified', 'paid'])
            ->whereBetween('created_at', [$day, $dayEnd])
            ->get();

        $collected = (float) $payments->sum(fn (Payment $payment): float => (float) $payment->amount);

        $balanceDue = $bookings->sum(function (Booking $booking): float {
            $paid = (float) $booking->payments
                ->filter(fn (Payment $payment): bool => in_array($payment->status, ['verified', 'paid'], true))
                ->sum(fn (Payment $payment): float => (float) $payment->amount);

            return max((float) $booking->total_amount - $paid, 0);
        });

        $byMethod = $payments
            ->groupBy(fn (Payment $payment): string => Contract::paymentMethod($payment->payment_method))
            ->map(fn ($group, string $method): array => [
                'method' => $method,
                'amount' => Contract::money($group->sum(fn (Payment $payment): float => (float) $payment->amount)),
            ])
            ->values()
            ->all();

        $bookedHours = $bookings->sum(
            fn (Booking $booking): float => $booking->starts_at->diffInMinutes($booking->ends_at) / 60
        );

        $courts = $club->courts()->where('is_active', true)->with('operatingHours')->get();
        $openHours = $courts->sum(function (Court $court) use ($day): float {
            $hours = collect(Contract::hours($court))->firstWhere('weekday', $day->dayOfWeek);

            if (! $hours || $hours['is_closed'] || ! $hours['opens_at'] || ! $hours['closes_at']) {
                return 0.0;
            }

            return (strtotime($hours['closes_at']) - strtotime($hours['opens_at'])) / 3600;
        });

        return $this->successResponse(data: [
            'date' => $validated['date'],
            'bookings_count' => $bookings->count(),
            'checked_out_count' => $bookings->where('status', 'completed')->count(),
            'collected_total' => Contract::money($collected),
            'balance_due_total' => Contract::money($balanceDue),
            'currency' => Contract::CURRENCY,
            'by_method' => $byMethod,
            'booked_hours' => round($bookedHours, 2),
            'open_hours' => round(max($openHours - $bookedHours, 0), 2),
        ]);
    }
}
