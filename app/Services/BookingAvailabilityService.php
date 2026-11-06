<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtBlockedSchedule;
use App\Models\CourtOperatingHour;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class BookingAvailabilityService
{
    /**
     * Check if a court is available between $startsAt and $endsAt.
     */
    public function isAvailable(Court $court, CarbonInterface|string $startsAt, CarbonInterface|string $endsAt, ?int $excludeBookingId = null): bool
    {
        $start = Carbon::parse($startsAt);
        $end = Carbon::parse($endsAt);

        if ($end->lessThanOrEqualTo($start)) {
            return false;
        }

        // 1. Court must be active
        if (! $court->is_active) {
            return false;
        }

        // 2. Check operating hours
        if (! $this->isWithinOperatingHours($court, $start, $end)) {
            return false;
        }

        // 3. Check blocked schedules
        if ($this->isBlocked($court, $start, $end)) {
            return false;
        }

        // 4. Check conflicting active bookings
        return ! $this->hasConflictingBooking($court, $start, $end, $excludeBookingId);
    }

    /**
     * Verify if the given window falls strictly within court operating hours.
     */
    public function isWithinOperatingHours(Court $court, Carbon $start, Carbon $end): bool
    {
        $dayOfWeek = $start->dayOfWeek; // 0=Sunday..6=Saturday
        $operatingHour = CourtOperatingHour::where('court_id', $court->id)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        // If no operating hours configured, allow by default
        if (! $operatingHour) {
            return true;
        }

        if ($operatingHour->is_closed) {
            return false;
        }

        $courtOpen = Carbon::parse($start->toDateString().' '.$operatingHour->open_time);
        $courtClose = Carbon::parse($start->toDateString().' '.$operatingHour->close_time);

        return $start->greaterThanOrEqualTo($courtOpen) && $end->lessThanOrEqualTo($courtClose);
    }

    /**
     * Check if court has a blocked schedule during this time.
     */
    public function isBlocked(Court $court, Carbon $start, Carbon $end): bool
    {
        return CourtBlockedSchedule::where('court_id', $court->id)
            ->where(function ($query) use ($start, $end): void {
                $query->where(function ($q) use ($start, $end): void {
                    $q->where('starts_at', '<', $end)
                        ->where('ends_at', '>', $start);
                });
            })
            ->exists();
    }

    /**
     * Check if there are overlapping active bookings.
     */
    public function hasConflictingBooking(Court $court, Carbon $start, Carbon $end, ?int $excludeBookingId = null): bool
    {
        return Booking::where('court_id', $court->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->when($excludeBookingId !== null, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->where(function ($query) use ($start, $end): void {
                $query->where('starts_at', '<', $end)
                    ->where('ends_at', '>', $start);
            })
            ->exists();
    }
}
