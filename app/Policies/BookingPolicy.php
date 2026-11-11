<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSystemAdmin()) {
            return true;
        }

        return null;
    }

    public function view(User $user, Booking $booking): bool
    {
        if ($booking->user_id === $user->id) {
            return true;
        }

        return $user->isOwnerOf($booking->tenant_id) || $user->isStaffOf($booking->tenant_id);
    }

    public function cancel(User $user, Booking $booking): bool
    {
        if ($booking->user_id === $user->id) {
            return true;
        }

        return $user->isOwnerOf($booking->tenant_id) || $user->isStaffOf($booking->tenant_id);
    }

    public function updateStatus(User $user, Booking $booking): bool
    {
        return $user->isOwnerOf($booking->tenant_id) || $user->isStaffOf($booking->tenant_id);
    }

    public function checkIn(User $user, Booking $booking): bool
    {
        return $user->isOwnerOf($booking->tenant_id) || $user->isStaffOf($booking->tenant_id);
    }
}
