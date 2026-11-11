<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Booking;
use App\Models\OpenPlayParticipant;
use App\Models\PlayerProfile;
use App\Support\Compat\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /player/home` — the player home screen in one call
 * (packages/api/src/player-types.ts PlayerHome).
 *
 * Whichever comes first: an upcoming court booking or an open-play session the
 * player is in. "Near" comes from the saved home barangay, never from GPS.
 */
class PlayerHomeController extends Controller
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = PlayerProfile::query()->where('user_id', $user->id)->first();

        $name = trim((string) ($profile?->display_name ?: $user->name));
        $greeting = $name === '' ? 'Player' : explode(' ', $name)[0];

        $booking = Booking::query()
            ->with(['court', 'tenant'])
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->first();

        $registration = OpenPlayParticipant::query()
            ->with(['session.tenant'])
            ->where('user_id', $user->id)
            ->whereIn('status', ['registered', 'waitlisted', 'checked_in'])
            ->whereHas('session', fn ($query) => $query
                ->whereIn('status', ['published', 'scheduled', 'live', 'in_progress'])
                ->where('starts_at', '>', now()))
            ->orderBy('id')
            ->get()
            ->sortBy(fn (OpenPlayParticipant $participant) => $participant->session?->starts_at)
            ->first();

        $next = null;

        if ($booking !== null
            && ($registration === null || $booking->starts_at->lessThanOrEqualTo($registration->session->starts_at))) {
            $booking->loadMissing(['payments', 'receipts.reviewer', 'user', 'creator']);

            $next = [
                'kind' => 'booking',
                'booking_id' => (string) $booking->id,
                'title' => (string) ($booking->court?->name ?? 'Court'),
                'venue_name' => (string) ($booking->tenant?->name ?? ''),
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'booking_code' => (string) $booking->booking_reference,
                'payment_status' => Contract::booking($booking)['payment_status'],
            ];
        } elseif ($registration !== null && $registration->session !== null) {
            $session = $registration->session;

            $next = [
                'kind' => 'open_play',
                'session_id' => (string) $session->id,
                'title' => (string) $session->title,
                'venue_name' => (string) ($session->tenant?->name ?? ''),
                'starts_at' => $session->starts_at->toIso8601String(),
                'ends_at' => $session->ends_at->toIso8601String(),
                'status' => $registration->status,
                'waitlist_position' => $registration->waitlist_position,
            ];
        }

        $home = Contract::homeLocation($profile?->barangay_id);

        return $this->successResponse(data: [
            'greeting_name' => $greeting,
            'next_game' => $next,
            'near' => $home === null ? null : [
                'city' => $home['city']['name'],
                'province' => $home['province']['name'],
            ],
        ]);
    }
}
