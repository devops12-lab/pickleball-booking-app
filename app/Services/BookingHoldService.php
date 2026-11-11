<?php

namespace App\Services;

use App\Models\Booking;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BookingHoldService
{
    /**
     * Cancel unpaid GCash/Maya holds whose time ran out.
     *
     * A receipt already waiting for staff keeps the hold open. The status
     * history records why the court was released so the venue can audit it.
     */
    public function cancelExpired(): int
    {
        $cancelled = 0;

        Booking::query()
            ->where('status', 'pending')
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<', now())
            ->whereDoesntHave('receipts', fn ($query) => $query->where('status', 'in_review'))
            ->orderBy('id')
            ->chunkById(100, function (Collection $bookings) use (&$cancelled): void {
                foreach ($bookings as $candidate) {
                    $didCancel = DB::transaction(function () use ($candidate): bool {
                        $booking = Booking::query()->lockForUpdate()->find($candidate->id);

                        if (
                            $booking === null
                            || $booking->status !== 'pending'
                            || $booking->hold_expires_at === null
                            || ! $booking->hold_expires_at->isPast()
                            || $booking->receipts()->where('status', 'in_review')->exists()
                        ) {
                            return false;
                        }

                        $booking->statusHistories()->create([
                            'changed_by' => null,
                            'from_status' => 'pending',
                            'to_status' => 'cancelled',
                            'reason' => 'The 30-minute hold ended before a receipt was checked',
                        ]);

                        $booking->update([
                            'status' => 'cancelled',
                            'hold_expires_at' => null,
                        ]);

                        return true;
                    });

                    if ($didCancel) {
                        $cancelled++;
                    }
                }
            });

        return $cancelled;
    }
}
