<?php

namespace App\Support\Compat;

use App\Models\Booking;
use App\Models\BookingReceipt;
use App\Models\Court;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\VenueProfile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;
use App\Models\Club;
use App\Models\OpenPlaySession;

/**
 * One place that turns backend rows into the exact shapes the SPA validates
 * (packages/api/src/schemas.ts). Money is always a decimal string, times are
 * always "HH:MM", timestamps always carry an offset.
 */
class Contract
{
    public const CURRENCY = 'PHP';

    /** @var list<string> */
    public const CLUB_STATUSES = ['active', 'pending_review', 'suspended', 'rejected'];

    /** @var list<string> */
    public const SUBSCRIPTION_STATUSES = [
        'trialing',
        'active',
        'past_due',
        'grace_period',
        'cancelled',
        'expired',
        'suspended',
    ];

    public static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    public static function clock(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return preg_match('/^(\d{2}:\d{2})/', (string) $value, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * @return array{id: string, name: string}|null
     */
    /**
     * The player's home address as `{id, code, name}` at each level, or null
     * when no barangay is saved (or it is not in the geo table).
     *
     * @return array<string, array{id: string, code: string, name: string}>|null
     */
    public static function homeLocation(?string $barangayId): ?array
    {
        $chain = \App\Models\GeoLocation::chain($barangayId);

        if ($chain === null) {
            return null;
        }

        $home = [];

        foreach (['country', 'region', 'province', 'city', 'barangay'] as $level) {
            $home[$level] = [
                'id' => (string) $chain[$level]->id,
                'code' => (string) $chain[$level]->code,
                'name' => (string) $chain[$level]->name,
            ];
        }

        return $home;
    }

    public static function person(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return ['id' => (string) $user->id, 'name' => (string) $user->name];
    }

    /**
     * Club operating rules. Stored values win; anything unset falls back to the
     * documented default below (see FIXED.md §6).
     *
     * @return array<string, mixed>
     */
    public static function defaultSettings(): array
    {
        return [
            'allow_online_booking' => true,
            'booking_payments' => [
                'pay_at_court' => true,
                'gcash' => null,
                'maya' => null,
                'require_prepayment' => false,
            ],
            'allow_guest_booking' => false,
            'allow_walk_ins' => true,
            'allow_partial_payments' => false,
            'default_slot_minutes' => 60,
            'booking_window_days' => 30,
            'allow_player_open_play' => true,
            'require_open_play_approval' => false,
            'member_join_policy' => 'open',
            'open_play_cancellation_hours' => 12,
            'cancellation_policy' => '',
            'player_cancellation_hours' => 12,
            'payment_instructions' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings(?VenueProfile $profile): array
    {
        $stored = is_array($profile?->settings) ? $profile->settings : [];
        $settings = array_replace_recursive(self::defaultSettings(), $stored);
        $payments = is_array($settings['booking_payments'] ?? null) ? $settings['booking_payments'] : [];

        // Older rows can store null for these text settings. The SPA contract
        // requires strings, so an unset policy reads as an empty one.
        $settings['cancellation_policy'] = (string) ($settings['cancellation_policy'] ?? '');
        $settings['payment_instructions'] = (string) ($settings['payment_instructions'] ?? '');

        // Older rows stored wallets as {name, number}; the SPA contract uses
        // account_name and account_number. Normalise both shapes on read.
        $settings['booking_payments'] = [
            'pay_at_court' => (bool) ($payments['pay_at_court'] ?? true),
            'gcash' => self::paymentWallet($payments['gcash'] ?? null),
            'maya' => self::paymentWallet($payments['maya'] ?? null),
            'require_prepayment' => (bool) ($payments['require_prepayment'] ?? false),
        ];

        return $settings;
    }

    /**
     * @return array{account_name: string, account_number: string, qr_url: string|null}|null
     */
    private static function paymentWallet(mixed $wallet): ?array
    {
        if (! is_array($wallet)) {
            return null;
        }

        $number = trim((string) ($wallet['account_number'] ?? $wallet['number'] ?? ''));

        if ($number === '') {
            return null;
        }

        return [
            'account_name' => (string) ($wallet['account_name'] ?? $wallet['name'] ?? ''),
            'account_number' => $number,
            'qr_url' => $wallet['qr_url'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function club(Tenant $tenant): array
    {
        $profile = $tenant->venueProfile;
        $status = $profile?->status;

        if (! in_array($status, self::CLUB_STATUSES, true)) {
            $status = $tenant->status === 'active' ? 'active' : 'suspended';
        }

        $subscriptionStatus = $tenant->subscription?->status;
        if (! in_array($subscriptionStatus, self::SUBSCRIPTION_STATUSES, true)) {
            $subscriptionStatus = 'trialing';
        }

        return [
            'id' => (string) $tenant->id,
            'slug' => (string) $tenant->slug,
            'name' => (string) $tenant->name,
            'tagline' => (string) ($profile?->tagline ?? ''),
            'description' => (string) ($profile?->description ?? ''),
            'rules' => (string) ($profile?->rules ?? ''),
            'logo_url' => $tenant->logo ?: null,
            'cover_url' => $profile?->cover_url,
            'contact_phone' => (string) ($profile?->contact_phone ?: ($tenant->business_phone ?? '')),
            'contact_email' => (string) ($profile?->contact_email ?: ($tenant->business_email ?? '')),
            'address' => (string) ($profile?->address ?: ($tenant->address ?? '')),
            'city' => (string) ($profile?->city ?? ''),
            'province' => (string) ($profile?->province ?? ''),
            'timezone' => (string) ($profile?->timezone ?: 'Asia/Manila'),
            'status' => $status,
            'status_reason' => $profile?->status_reason,
            'subscription_status' => $subscriptionStatus,
            'read_only' => $status !== 'active'
                || in_array($subscriptionStatus, ['expired', 'suspended', 'cancelled'], true),
            'settings' => self::settings($profile),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function court(Court $court): array
    {
        return [
            'id' => (string) $court->id,
            'club_id' => (string) $court->tenant_id,
            'name' => (string) $court->name,
            'court_code' => (string) ($court->court_code ?: 'C-'.$court->id),
            'indoor_outdoor' => $court->court_type === 'outdoor' ? 'outdoor' : 'indoor',
            'surface_type' => (string) ($court->surface_type ?? ''),
            'base_price' => self::money($court->hourly_rate),
            'currency' => self::CURRENCY,
            'status' => in_array($court->status, ['active', 'inactive', 'maintenance'], true)
                ? $court->status
                : ($court->is_active ? 'active' : 'inactive'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function courtDetail(Court $court): array
    {
        return [
            ...self::court($court),
            'hours' => self::hours($court),
            'price_rules' => self::priceRules($court),
        ];
    }

    /**
     * Seven rows, one per weekday. A day with no stored row gets the default
     * 06:00-22:00 opening, which is what the first save will persist.
     *
     * @return list<array<string, mixed>>
     */
    public static function hours(Court $court): array
    {
        $rows = $court->operatingHours->keyBy('day_of_week');
        $hours = [];

        for ($weekday = 0; $weekday <= 6; $weekday++) {
            $row = $rows->get($weekday);

            if (! $row) {
                $hours[] = [
                    'weekday' => $weekday,
                    'is_closed' => false,
                    'opens_at' => '06:00',
                    'closes_at' => '22:00',
                ];

                continue;
            }

            $closed = (bool) $row->is_closed;
            $hours[] = [
                'weekday' => $weekday,
                'is_closed' => $closed,
                'opens_at' => $closed ? null : self::clock($row->open_time),
                'closes_at' => $closed ? null : self::clock($row->close_time),
            ];
        }

        return $hours;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function priceRules(Court $court): array
    {
        return $court->priceRules
            ->map(fn ($rule): array => [
                'id' => (string) $rule->id,
                'court_id' => (string) $rule->court_id,
                'day_of_week' => $rule->day_of_week === null ? null : (int) $rule->day_of_week,
                'starts_at' => self::clock($rule->starts_at) ?? '00:00',
                'ends_at' => self::clock($rule->ends_at) ?? '00:00',
                'price' => self::money($rule->price),
                'currency' => self::CURRENCY,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function subscription(Tenant $tenant, TenantSubscription $subscription): array
    {
        $subscription->loadMissing(['plan', 'invoices']);

        $plan = $subscription->plan;
        $trialEnds = $subscription->trial_ends_at;
        $trialDaysLeft = $trialEnds ? max((int) now()->diffInDays($trialEnds, false), 0) : null;

        $entitlements = [];

        if ($plan) {
            $entitlements[] = [
                'feature_key' => 'courts',
                'label' => 'Courts',
                'limit_value' => $plan->max_courts === null ? 'unlimited' : (string) $plan->max_courts,
                'usage' => $tenant->courts()->count(),
            ];
            $entitlements[] = [
                'feature_key' => 'staff',
                'label' => 'Staff seats',
                'limit_value' => $plan->max_staff === null ? 'unlimited' : (string) $plan->max_staff,
                'usage' => $tenant->tenantUsers()->where('status', 'active')->count(),
            ];

            foreach ((array) ($plan->features ?? []) as $key => $value) {
                if (is_int($key)) {
                    $entitlements[] = [
                        'feature_key' => (string) $value,
                        'label' => Str::headline((string) $value),
                        'limit_value' => 'included',
                    ];

                    continue;
                }

                $entitlements[] = [
                    'feature_key' => (string) $key,
                    'label' => Str::headline((string) $key),
                    'limit_value' => is_bool($value)
                        ? ($value ? 'included' : 'not_included')
                        : (string) $value,
                ];
            }
        }

        $status = match ((string) $subscription->status) {
            'active' => 'active',
            'past_due' => 'past_due',
            'grace_period' => 'grace_period',
            'canceled', 'cancelled' => 'cancelled',
            'expired' => 'expired',
            'suspended' => 'suspended',
            default => 'trialing',
        };

        return [
            'id' => (string) $subscription->id,
            'club_id' => (string) $tenant->id,
            'status' => $status,
            'plan' => [
                'code' => (string) ($plan?->slug ?? ''),
                'name' => (string) ($plan?->name ?? ''),
                'price' => self::money($plan?->price ?? 0),
                'currency' => self::CURRENCY,
                'billing_interval' => $plan?->billing_interval === 'yearly' ? 'year' : 'month',
            ],
            'trial_starts_at' => $status === 'trialing' ? $subscription->starts_at?->toIso8601String() : null,
            'trial_ends_at' => $trialEnds?->toIso8601String(),
            'trial_days_left' => $trialDaysLeft,
            'current_period_starts_at' => $subscription->starts_at?->toIso8601String(),
            'current_period_ends_at' => $subscription->ends_at?->toIso8601String(),
            'entitlements' => $entitlements,
            'invoices' => $subscription->invoices->map(fn ($invoice): array => [
                'id' => (string) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'amount' => self::money($invoice->amount),
                'currency' => self::CURRENCY,
                'status' => match ((string) $invoice->status) {
                    'paid' => 'paid',
                    'pending', 'issued' => 'issued',
                    'failed', 'overdue' => 'overdue',
                    'void' => 'void',
                    'draft' => 'draft',
                    default => 'issued',
                },
                'issued_at' => $invoice->created_at->toIso8601String(),
                'due_at' => ($invoice->due_at ?? $invoice->created_at)->toIso8601String(),
                'paid_at' => $invoice->paid_at?->toIso8601String(),
                'payment_method' => $invoice->payment_method,
                'payment_reference' => $invoice->payment_reference,
                'void_reason' => $invoice->void_reason,
            ])->values()->all(),
        ];
    }

    public const BOOKING_SOURCES = ['player_app', 'guest_web', 'staff_walk_in', 'owner_created'];

    public const PAY_METHODS = ['pay_at_court', 'gcash', 'maya'];

    public static function bookingStatus(mixed $status): string
    {
        return match ((string) $status) {
            'confirmed' => 'confirmed',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            'expired' => 'expired',
            default => 'pending',
        };
    }

    /** Backend payment method => SPA PaymentMethod. */
    public static function paymentMethod(mixed $method): string
    {
        return match (strtolower((string) $method)) {
            'cash' => 'cash',
            'gcash' => 'gcash_at_venue',
            'maya' => 'maya_at_venue',
            'card' => 'card_at_venue',
            default => 'other',
        };
    }

    /** SPA PaymentMethod => backend payment method string. */
    public static function backendPaymentMethod(string $method): string
    {
        return match ($method) {
            'gcash_at_venue' => 'gcash',
            'maya_at_venue' => 'maya',
            'card_at_venue' => 'card',
            'cash' => 'cash',
            default => 'other',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function receipt(?BookingReceipt $receipt): ?array
    {
        if (! $receipt) {
            return null;
        }

        return [
            'image_url' => (string) $receipt->image_url,
            'reference_number' => (string) $receipt->reference_number,
            'submitted_at' => $receipt->created_at->toIso8601String(),
            'status' => in_array($receipt->status, ['in_review', 'approved', 'rejected'], true)
                ? $receipt->status
                : 'in_review',
            'rejection_reason' => $receipt->rejection_reason,
            'reviewed_by' => self::person($receipt->reviewer),
            'reviewed_at' => $receipt->reviewed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function booking(Booking $booking): array
    {
        $booking->loadMissing(['user', 'creator', 'payments', 'receipts.reviewer']);

        $collected = $booking->payments
            ->filter(fn (Payment $payment): bool => in_array($payment->status, ['verified', 'paid'], true))
            ->sum(fn (Payment $payment): float => (float) $payment->amount);

        $total = (float) $booking->total_amount;
        $paid = (float) $collected;

        $paymentStatus = match (true) {
            $total > 0 && $paid >= $total => 'paid',
            $paid > 0 => 'partially_paid',
            default => 'unpaid',
        };

        $source = in_array($booking->source, self::BOOKING_SOURCES, true)
            ? $booking->source
            : 'player_app';

        $payMethod = in_array($booking->payment_method, self::PAY_METHODS, true)
            ? $booking->payment_method
            : null;

        return [
            'id' => (string) $booking->id,
            'club_id' => (string) $booking->tenant_id,
            'court_id' => (string) $booking->court_id,
            // This backend has no separate reservation row; the booking is the block.
            'reservation_id' => (string) $booking->id,
            'booking_code' => (string) $booking->booking_reference,
            'source' => $source,
            'starts_at' => $booking->starts_at->toIso8601String(),
            'ends_at' => $booking->ends_at->toIso8601String(),
            'status' => self::bookingStatus($booking->status),
            'payment_status' => $paymentStatus,
            'subtotal' => self::money($total),
            // No discount is stored yet, so it is always zero.
            'discount' => '0.00',
            'total' => self::money($total),
            'amount_paid' => self::money($paid),
            'balance_due' => self::money(max($total - $paid, 0)),
            'currency' => self::CURRENCY,
            'player' => self::person($booking->user),
            'guest_name' => $booking->guest_name,
            'guest_phone' => $booking->guest_phone,
            'guest_email' => $booking->guest_email,
            'notes' => $booking->notes,
            'created_by' => self::person($booking->creator)
                ?? self::person($booking->user)
                ?? (filled($booking->guest_email)
                    ? ['id' => 'guest:'.$booking->guest_email, 'name' => trim((string) $booking->guest_name).' (guest)']
                    : ['id' => '0', 'name' => 'System']),
            'created_at' => $booking->created_at->toIso8601String(),
            'payment_method' => $payMethod,
            'hold_expires_at' => $booking->hold_expires_at?->toIso8601String(),
            'receipt' => self::receipt($booking->receipts->sortByDesc('id')->first()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function paymentRecord(Payment $payment): array
    {
        return [
            'id' => (string) $payment->id,
            'booking_id' => (string) $payment->booking_id,
            'amount' => self::money($payment->amount),
            'currency' => self::CURRENCY,
            'method' => self::paymentMethod($payment->payment_method),
            'status' => $payment->status === 'refunded' ? 'voided' : 'paid',
            'reference_number' => $payment->transaction_reference,
            'received_by' => self::person($payment->user) ?? ['id' => '0', 'name' => 'System'],
            'received_at' => $payment->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adjustment(Refund $refund): array
    {
        return [
            'id' => (string) $refund->id,
            'payment_record_id' => (string) $refund->payment_id,
            'booking_id' => (string) (Payment::find($refund->payment_id)?->booking_id ?? 0),
            'amount' => self::money($refund->amount),
            'currency' => self::CURRENCY,
            'adjustment_type' => in_array($refund->adjustment_type, ['refund', 'correction'], true)
                ? $refund->adjustment_type
                : 'refund',
            'reason' => (string) $refund->reason,
            'approved_by' => self::person(User::find($refund->processed_by)) ?? ['id' => '0', 'name' => 'System'],
            'created_at' => $refund->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function openPlayStatus(OpenPlaySession|string $session): string
    {
        $stored = $session instanceof OpenPlaySession ? (string) $session->status : (string) $session;

        $status = match ($stored) {
            'scheduled', 'published' => 'published',
            'live', 'in_progress' => 'live',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            'rejected' => 'rejected',
            'pending_approval' => 'pending_approval',
            default => 'published',
        };

        // A published session rolls into live/completed with the clock, the
        // same way the SPA's mock does, without rewriting the stored row.
        if ($session instanceof OpenPlaySession
            && $status === 'published'
            && $session->starts_at !== null
            && $session->ends_at !== null) {
            $now = now();

            if ($session->ends_at->lessThanOrEqualTo($now)) {
                return 'completed';
            }

            if ($session->starts_at->lessThanOrEqualTo($now)) {
                return 'live';
            }
        }

        return $status;
    }

    public static function skillLevel(OpenPlaySession $session): string
    {
        if (filled($session->skill_level)) {
            return (string) $session->skill_level;
        }

        $value = $session->skill_level_min ?? $session->skill_level_max;

        if ($value === null) {
            return 'all';
        }

        return match (true) {
            (float) $value < 2.0 => 'beginner',
            (float) $value < 2.5 => 'novice',
            (float) $value < 3.5 => 'intermediate',
            (float) $value < 4.5 => 'advanced',
            default => 'pro',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function organizerRef(string $type, int $id, ?Tenant $tenant = null, ?Club $club = null): array
    {
        if ($type === 'community' && $club !== null) {
            return [
                'type' => 'community',
                'id' => (string) $club->id,
                'slug' => (string) $club->slug,
                'name' => (string) $club->name,
                'logo_url' => $club->logo ?: null,
            ];
        }

        return [
            'type' => 'venue',
            'id' => (string) ($tenant?->id ?? $id),
            'slug' => (string) ($tenant?->slug ?? ''),
            'name' => (string) ($tenant?->name ?? ''),
            'logo_url' => null,
        ];
    }

    public static function canManageOpenPlay(OpenPlaySession $session, ?int $viewerId): bool
    {
        if ($viewerId === null) {
            return false;
        }

        if ((int) $session->host_id === $viewerId) {
            return true;
        }

        if ($session->organizer_type === 'community') {
            return DB::table('club_members')
                ->where('club_id', $session->organizer_id)
                ->where('user_id', $viewerId)
                ->whereIn('role', ['owner', 'admin'])
                ->where('status', 'active')
                ->exists();
        }

        return DB::table('tenant_users')
            ->where('tenant_id', $session->tenant_id)
            ->where('user_id', $viewerId)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public static function openPlaySession(OpenPlaySession $session, ?int $viewerId): array
    {
        $session->loadMissing([
            'court', 'courts', 'host', 'tenant.venueProfile', 'tenant.subscription',
            'organizerClub', 'participants.user', 'feePayments.refunds',
        ]);

        $tenant = $session->tenant;
        $profile = $tenant?->venueProfile;
        $settings = self::settings($profile);
        $type = $session->organizer_type === 'community' ? 'community' : 'venue';

        $participants = $session->participants;
        $active = $participants->filter(fn ($p) => in_array($p->status, ['registered', 'checked_in', 'completed'], true));
        $waitlisted = $participants->filter(fn ($p) => $p->status === 'waitlisted');
        $checked = $participants->filter(fn ($p) => in_array($p->status, ['checked_in', 'completed'], true));
        $mine = $viewerId === null
            ? null
            : $participants->first(fn ($p) => (int) $p->user_id === $viewerId && $p->status !== 'cancelled');

        $hours = (int) ($settings['open_play_cancellation_hours'] ?? 12);
        $courts = $session->courts->isNotEmpty()
            ? $session->courts->map(fn (Court $court) => ['id' => (string) $court->id, 'name' => (string) $court->name])->values()->all()
            : ($session->court === null ? [] : [['id' => (string) $session->court->id, 'name' => (string) $session->court->name]]);

        return [
            'id' => (string) $session->id,
            'organizer' => self::organizerRef($type, (int) ($session->organizer_id ?? $session->tenant_id), $tenant, $session->organizerClub),
            'venue' => [
                'id' => (string) $tenant->id,
                'slug' => (string) $tenant->slug,
                'name' => (string) $tenant->name,
                'city' => (string) ($profile?->city ?? ''),
            ],
            'host' => self::person($session->host)
                ?? ['id' => (string) ($session->host_id ?? 0), 'name' => 'Host'],
            'title' => (string) $session->title,
            'description' => (string) ($session->description ?? ''),
            'session_type' => (string) ($session->session_type ?: 'open_play'),
            'skill_level' => self::skillLevel($session),
            'starts_at' => $session->starts_at->toIso8601String(),
            'ends_at' => $session->ends_at->toIso8601String(),
            'registration_deadline' => ($session->registration_deadline ?? $session->starts_at)->toIso8601String(),
            'courts' => $courts,
            'capacity' => (int) $session->max_participants,
            'registered_count' => $active->count(),
            'waitlist_count' => $waitlisted->count(),
            'checked_in_count' => $checked->count(),
            'fee' => self::money($session->fee),
            'member_fee' => self::money($session->member_fee),
            'currency' => self::CURRENCY,
            'members_only' => (bool) $session->members_only,
            'status' => self::openPlayStatus($session),
            'status_reason' => $session->status_reason,
            'court_fee' => self::money($session->court_fee),
            'court_fee_paid' => self::money(self::courtFeePaid($session)),
            'my_registration' => $mine === null ? null : [
                'id' => (string) $mine->id,
                'status' => $mine->status ?: 'registered',
                'waitlist_position' => $mine->waitlist_position,
                'fee_due' => self::money($mine->fee_due),
                'fee_status' => self::participantFeeStatus($session, $mine),
                'cancel_until' => $session->starts_at->toImmutable()->subHours($hours)->toIso8601String(),
            ],
            'can_manage' => self::canManageOpenPlay($session, $viewerId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function openPlayDetail(OpenPlaySession $session, ?int $viewerId): array
    {
        $base = self::openPlaySession($session, $viewerId);
        $participants = $session->participants;
        $manage = $base['can_manage'];
        $settings = self::settings($session->tenant?->venueProfile);
        $hours = (int) ($settings['open_play_cancellation_hours'] ?? 12);

        $roster = $participants
            ->filter(fn ($p) => in_array($p->status, ['registered', 'checked_in', 'waitlisted'], true))
            ->sortBy(fn ($p) => [$p->waitlist_position ?? 0, $p->created_at?->getTimestamp() ?? 0])
            ->map(fn ($p) => [
                'player' => self::person($p->user) ?? ['id' => (string) $p->user_id, 'name' => 'Player'],
                'status' => $p->status,
                'waitlist_position' => $p->waitlist_position,
            ])
            ->values()
            ->all();

        $list = $manage
            ? $participants
                ->sortBy(fn ($p) => $p->created_at?->getTimestamp() ?? 0)
                ->map(function ($p) use ($session): array {
                    $profile = DB::table('player_profiles')->where('user_id', $p->user_id)->value('skill_level');

                    return [
                        'id' => (string) $p->id,
                        'player' => [
                            'id' => (string) $p->user_id,
                            'name' => (string) ($p->user?->name ?? 'Player'),
                            'skill_level' => $profile ?: 'intermediate',
                        ],
                        'status' => $p->status,
                        'queue_state' => $p->queue_state,
                        'waitlist_position' => $p->waitlist_position,
                        'is_member' => (bool) $p->is_member,
                        'registered_at' => ($p->created_at ?? now())->toIso8601String(),
                        'checked_in_at' => $p->checked_in_at?->toIso8601String(),
                        'fee_due' => self::money($p->fee_due),
                        'fee_paid' => self::money(self::participantFeePaid($session, $p)),
                        'fee_status' => self::participantFeeStatus($session, $p),
                        'games_played' => (int) $p->games_played,
                    ];
                })
                ->values()
                ->all()
            : [];

        return $base + [
            'participants' => $list,
            'roster' => $roster,
            'cancellation_hours' => $hours,
        ];
    }

    public static function courtFeePaid(OpenPlaySession $session): float
    {
        return max(0, (float) $session->feePayments
            ->where('kind', 'court_fee')
            ->sum(fn ($payment) => (float) $payment->amount - (float) $payment->refunds->sum('amount')));
    }

    public static function participantFeePaid(OpenPlaySession $session, $participant): float
    {
        return max(0, (float) $session->feePayments
            ->where('kind', 'session_fee')
            ->where('open_play_participant_id', $participant->id)
            ->sum(fn ($payment) => (float) $payment->amount - (float) $payment->refunds->sum('amount')));
    }

    public static function participantFeeStatus(OpenPlaySession $session, $participant): string
    {
        $payments = $session->feePayments
            ->where('kind', 'session_fee')
            ->where('open_play_participant_id', $participant->id);
        $paid = (float) $payments->sum('amount');
        $refunded = (float) $payments->sum(fn ($payment) => (float) $payment->refunds->sum('amount'));

        if ($paid > 0 && $refunded >= $paid) {
            return 'refunded';
        }

        if ((float) $participant->fee_due <= 0) {
            return 'free';
        }

        return $paid - $refunded >= (float) $participant->fee_due ? 'paid' : 'unpaid';
    }

    public static function bookingDetail(Booking $booking): array
    {
        $booking->loadMissing(['payments.user', 'statusHistories.user', 'receipts.reviewer']);

        $payments = $booking->payments
            ->filter(fn (Payment $payment): bool => in_array($payment->status, ['verified', 'paid', 'refunded'], true))
            ->map(fn (Payment $payment): array => self::paymentRecord($payment))
            ->values()
            ->all();

        $adjustments = Refund::query()
            ->whereIn('payment_id', $booking->payments->pluck('id'))
            ->get()
            ->map(fn (Refund $refund): array => self::adjustment($refund))
            ->values()
            ->all();

        $history = $booking->statusHistories
            ->sortBy('created_at')
            ->map(fn ($entry): array => [
                'old_status' => $entry->from_status ? self::bookingStatus($entry->from_status) : null,
                'new_status' => self::bookingStatus($entry->to_status),
                'changed_by' => self::person($entry->user) ?? ['id' => '0', 'name' => 'System'],
                'reason' => $entry->reason,
                'changed_at' => $entry->created_at->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            ...self::booking($booking),
            'payments' => $payments,
            'adjustments' => $adjustments,
            'status_history' => $history,
        ];
    }

    /**
     * Hourly rate for one slot: the matching time-of-day rule, else the base rate.
     */
    public static function priceForSlot(Court $court, CarbonImmutable $startsAt, int $minutes): float
    {
        $weekday = $startsAt->dayOfWeek;
        $clock = $startsAt->format('H:i');

        $rule = $court->priceRules->first(function ($candidate) use ($weekday, $clock): bool {
            $dayMatches = $candidate->day_of_week === null || (int) $candidate->day_of_week === $weekday;
            $startsAt = self::clock($candidate->starts_at) ?? '00:00';
            $endsAt = self::clock($candidate->ends_at) ?? '24:00';

            return $dayMatches && $clock >= $startsAt && $clock < $endsAt;
        });

        $hourly = $rule ? (float) $rule->price : (float) $court->hourly_rate;

        return round($hourly * ($minutes / 60), 2);
    }
}
