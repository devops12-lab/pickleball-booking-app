<?php

namespace App\Support\Compat;

use App\Models\Booking;
use App\Models\Court;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * The player-facing side of a booking (packages/api/src/booking-types.ts):
 * quotes, the booking itself, and the venue's day grid.
 */
class PlayerBookingView
{
    /**
     * Price broken into the 30-minute rate bands the booking crosses.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public static function lines(Court $court, CarbonImmutable $start, int $minutes): array
    {
        $lines = [];

        for ($offset = 0; $offset < $minutes; $offset += 30) {
            $segment = $start->addMinutes($offset);
            $hourly = Contract::priceForSlot($court, $segment, 60);
            $amount = round($hourly / 2, 2);
            $last = $lines === [] ? null : $lines[count($lines) - 1];

            if ($last !== null && (float) $last['hourly_rate'] === (float) $hourly) {
                $lines[count($lines) - 1]['ends_at'] = $segment->addMinutes(30)->toIso8601String();
                $lines[count($lines) - 1]['amount'] = Contract::money((float) $last['amount'] + $amount);

                continue;
            }

            $lines[] = [
                'starts_at' => $segment->toIso8601String(),
                'ends_at' => $segment->addMinutes(30)->toIso8601String(),
                'hourly_rate' => Contract::money($hourly),
                'amount' => Contract::money($amount),
            ];
        }

        $total = array_sum(array_map(fn (array $line): float => (float) $line['amount'], $lines));

        return [$lines, Contract::money($total)];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function quote(Tenant $tenant, Court $court, CarbonImmutable $start, int $minutes, array $settings): array
    {
        [$lines, $total] = self::lines($court, $start, $minutes);

        return [
            'court_id' => (string) $court->id,
            'court_name' => (string) $court->name,
            'venue_id' => (string) $tenant->id,
            'venue_slug' => (string) $tenant->slug,
            'venue_name' => (string) $tenant->name,
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->addMinutes($minutes)->toIso8601String(),
            'duration_minutes' => $minutes,
            'lines' => $lines,
            'total' => $total,
            'currency' => Contract::CURRENCY,
            'cancel_until' => self::cancelUntil($start, $settings)->toIso8601String(),
            'cancellation_policy' => (string) ($settings['cancellation_policy'] ?? ''),
            'payment_instructions' => (string) ($settings['payment_instructions'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function cancelUntil(CarbonImmutable $startsAt, array $settings): CarbonImmutable
    {
        return $startsAt->subHours((int) ($settings['player_cancellation_hours'] ?? 12));
    }

    /**
     * @return array<string, mixed>
     */
    public static function view(Booking $booking, string $viewerId): array
    {
        $booking->loadMissing([
            'user', 'creator', 'payments', 'receipts.reviewer',
            'court', 'tenant.venueProfile', 'tenant.subscription', 'statusHistories.user',
        ]);

        $base = Contract::booking($booking);
        $tenant = $booking->tenant;
        $court = $booking->court;
        $profile = $tenant?->venueProfile;
        $settings = Contract::settings($profile);
        $club = $tenant ? Contract::club($tenant) : null;

        $until = self::cancelUntil($booking->starts_at->toImmutable(), $settings);
        $upcoming = in_array($booking->status, ['pending', 'confirmed'], true)
            && $booking->starts_at->isFuture();
        $paid = (float) $base['amount_paid'];
        $blocked = null;

        if ($upcoming) {
            if ($paid > 0) {
                $blocked = "You've paid ₱".number_format($paid, 2)
                    ." at the venue. Call {$tenant->name} to cancel so they can refund you.";
            } elseif ($until->isPast()) {
                $blocked = "It's too late to cancel in the app. Call {$tenant->name} to cancel.";
            } elseif ($club !== null && $club['read_only']) {
                $blocked = "{$tenant->name} can't take changes in CourtFlex right now. Call them to cancel.";
            }
        }

        $cancelled = $booking->status === 'cancelled'
            ? $booking->statusHistories
                ->sortByDesc('created_at')
                ->first(fn ($entry) => $entry->to_status === 'cancelled')
            : null;

        $payments = is_array($settings['booking_payments'] ?? null) ? $settings['booking_payments'] : [];
        $method = $booking->payment_method;

        $receipt = $base['receipt'];
        if (is_array($receipt)) {
            // The player doesn't see which staff member checked it.
            unset($receipt['reviewed_by']);
        }

        return [
            'id' => (string) $booking->id,
            'booking_code' => (string) $booking->booking_reference,
            'source' => $base['source'],
            'status' => $base['status'],
            'payment_status' => $base['payment_status'],
            'starts_at' => $booking->starts_at->toIso8601String(),
            'ends_at' => $booking->ends_at->toIso8601String(),
            'duration_minutes' => (int) $booking->starts_at->diffInMinutes($booking->ends_at),
            'total' => $base['total'],
            'amount_paid' => $base['amount_paid'],
            'balance_due' => $base['balance_due'],
            'currency' => Contract::CURRENCY,
            'notes' => $booking->notes,
            'player' => $base['player'],
            'guest_name' => $base['guest_name'],
            'guest_phone' => $base['guest_phone'],
            'guest_email' => $base['guest_email'],
            'court' => [
                'id' => (string) $court->id,
                'name' => (string) $court->name,
                'indoor_outdoor' => $court->court_type === 'outdoor' ? 'outdoor' : 'indoor',
                'surface_type' => (string) ($court->surface_type ?? ''),
            ],
            'venue' => [
                'id' => (string) $tenant->id,
                'slug' => (string) $tenant->slug,
                'name' => (string) $tenant->name,
                'address' => (string) ($profile?->address ?: ($tenant->address ?? '')),
                'city' => (string) ($profile?->city ?? ''),
                'province' => (string) ($profile?->province ?? ''),
                'contact_phone' => (string) ($profile?->contact_phone ?: ($tenant->business_phone ?? '')),
            ],
            'cancellation_policy' => (string) ($settings['cancellation_policy'] ?? ''),
            'payment_instructions' => (string) ($settings['payment_instructions'] ?? ''),
            'payment_method' => $base['payment_method'],
            'hold_expires_at' => $booking->hold_expires_at?->toIso8601String(),
            'pay_to' => in_array($method, ['gcash', 'maya'], true)
                ? ($payments[$method] ?? null)
                : null,
            'receipt' => $receipt,
            'cancel_until' => $until->toIso8601String(),
            'can_cancel' => $upcoming && $blocked === null,
            'cancel_blocked_reason' => $blocked,
            'cancellation' => $cancelled === null ? null : [
                'cancelled_at' => $cancelled->created_at->toIso8601String(),
                // Guests have no user id, so their own cancellation is the one they
                // are looking at; the code and email already proved it is theirs.
                'by_player' => (string) $cancelled->changed_by === $viewerId
                    || ($booking->source === 'guest_web' && str_starts_with($viewerId, 'guest:')),
                'reason' => (string) $cancelled->changed_by === $viewerId
                    || ($booking->source === 'guest_web' && str_starts_with($viewerId, 'guest:'))
                        ? $cancelled->reason
                        : null,
            ],
            'created_at' => $booking->created_at->toIso8601String(),
        ];
    }
}
