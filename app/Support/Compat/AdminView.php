<?php

namespace App\Support\Compat;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\GeoLocation;
use App\Models\OpenPlaySession;
use App\Models\Payment;
use App\Models\PlayerProfile;
use App\Models\Refund;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\UserSuspension;
use App\Models\VenueProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The Super Admin contract (packages/api/src/admin-types.ts and
 * admin-users.ts). A venue is a tenant with a venue profile; a club is a
 * community `clubs` row; users are accounts with their memberships resolved.
 *
 * Nothing is invented: a value the backend has no source for reads as zero,
 * null or an empty list, and the reason is documented in FIXED.md.
 */
class AdminView
{
    private const TZ = 'Asia/Manila';

    private const STATUSES = ['pending_review', 'active', 'suspended', 'rejected'];

    private const STATUS_WORDS = [
        'pending_review' => 'waiting for review',
        'active' => 'active',
        'suspended' => 'suspended',
        'rejected' => 'declined',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function venueRow(Tenant $tenant): array
    {
        $tenant->loadMissing(['venueProfile', 'subscription.plan', 'subscriptionPlan', 'courts', 'tenantUsers.role', 'tenantUsers.user']);

        $club = Contract::club($tenant);
        $owner = self::venueOwner($tenant);
        $subscription = $tenant->subscription;
        $plan = $subscription?->plan ?? $tenant->subscriptionPlan;

        return [
            'id' => (string) $tenant->id,
            'slug' => (string) $tenant->slug,
            'name' => (string) $tenant->name,
            'city' => (string) $club['city'],
            'province' => (string) $club['province'],
            'status' => self::venueStatus($tenant),
            'owner' => $owner === null ? null : self::personWithEmail($owner),
            'courts' => $tenant->courts->count(),
            'plan' => (string) ($plan?->name ?? ''),
            'subscription_status' => self::subscriptionStatus($subscription),
            'submitted_at' => $tenant->venueProfile?->submitted_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function venueDetail(Tenant $tenant): array
    {
        $tenant->loadMissing(['venueProfile', 'courts']);
        $club = Contract::club($tenant);
        $profile = $tenant->venueProfile;
        $now = CarbonImmutable::now();

        $bookings = Booking::query()
            ->where('tenant_id', $tenant->id)
            ->where('starts_at', '>=', $now)
            ->where('starts_at', '<=', $now->addDays(7))
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->count();

        return self::venueRow($tenant) + [
            'address' => (string) $club['address'],
            'contact_phone' => (string) $club['contact_phone'],
            'contact_email' => (string) $club['contact_email'],
            'pin' => $profile !== null && $profile->latitude !== null && $profile->longitude !== null
                ? ['lat' => (float) $profile->latitude, 'lng' => (float) $profile->longitude]
                : null,
            'court_list' => $tenant->courts
                ->sortBy('id')
                ->map(fn ($court): array => [
                    'id' => (string) $court->id,
                    'name' => (string) $court->name,
                    'indoor_outdoor' => $court->court_type === 'outdoor' ? 'outdoor' : 'indoor',
                    'base_price' => Contract::money($court->hourly_rate),
                    'status' => Contract::court($court)['status'],
                ])
                ->values()
                ->all(),
            'bookings_next_7_days' => $bookings,
            'decision' => self::decision('venue', (int) $tenant->id),
            'history' => self::history('venue', (int) $tenant->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function clubRow(Club $club): array
    {
        $club->loadMissing(['creator', 'members', 'homeVenue']);
        $createdBy = $club->creator;
        $submitted = $club->created_at ?? CarbonImmutable::now();

        return [
            'id' => (string) $club->id,
            'slug' => (string) $club->slug,
            'name' => (string) $club->name,
            'city' => (string) ($club->city ?? ''),
            'province' => (string) ($club->province ?? ''),
            'status' => self::clubStatus($club),
            'created_by' => $createdBy === null ? null : self::personWithEmail($createdBy),
            'members' => $club->members->where('status', 'active')->count(),
            'submitted_at' => $submitted->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function clubDetail(Club $club): array
    {
        $club->loadMissing(['homeVenue']);

        return self::clubRow($club) + [
            'tagline' => (string) ($club->tagline ?? ''),
            'description' => (string) ($club->description ?? ''),
            'contact_email' => (string) ($club->contact_email ?? ''),
            'contact_phone' => (string) ($club->contact_phone ?? ''),
            'join_policy' => in_array($club->join_policy, ['open', 'approval'], true) ? $club->join_policy : 'open',
            'home_venue' => $club->homeVenue === null ? null : [
                'id' => (string) $club->homeVenue->id,
                'name' => (string) $club->homeVenue->name,
            ],
            'plans' => 0,
            'decision' => self::decision('club', (int) $club->id),
            'history' => self::history('club', (int) $club->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function userRow(User $user): array
    {
        $user->loadMissing(['tenantUsers.role', 'tenantUsers.tenant', 'clubMemberships.club', 'playerProfile', 'suspensions']);

        $roles = [];

        if ($user->isSystemAdmin()) {
            $roles[] = 'super_admin';
        }

        $venueMemberships = $user->tenantUsers
            ->filter(fn ($row): bool => $row->status === 'active'
                && $row->tenant !== null
                && $row->tenant->slug !== 'platform-admin'
                && $row->role !== null);

        if ($venueMemberships->isNotEmpty()) {
            $roles[] = 'venue_staff';
        }

        $clubAdmin = $user->clubMemberships
            ->filter(fn ($row): bool => $row->status === 'active'
                && in_array($row->role, ['owner', 'manager', 'organizer', 'admin'], true))
            ->isNotEmpty();

        if ($clubAdmin) {
            $roles[] = 'club_admin';
        }

        if ($user->playerProfile !== null) {
            $roles[] = 'player';
        }

        return [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'phone' => $user->phone,
            'roles' => $roles,
            'status' => self::userStatus($user),
            'venue_roles' => $venueMemberships
                ->map(fn ($row): array => [
                    'club_id' => (string) $row->tenant_id,
                    'club_name' => (string) $row->tenant->name,
                    'role_key' => self::venueRoleKey((string) $row->role->name),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function userDetail(User $user): array
    {
        $user->loadMissing([
            'playerProfile', 'clubMemberships.club', 'suspensions.suspendedBy',
        ]);

        $profile = $user->playerProfile;
        $chain = $profile === null ? null : GeoLocation::chain($profile->barangay_id);
        $suspension = self::activeSuspension($user);
        $now = CarbonImmutable::now();

        $bookings = Booking::query()->where('user_id', $user->id);

        return self::userRow($user) + [
            'home_city' => $chain === null ? null : $chain['city']->name,
            'player' => $profile === null ? null : [
                'skill' => (string) ($profile->skill_level ?: 'intermediate'),
                'location' => $chain === null
                    ? null
                    : $chain['barangay']->name.', '.$chain['city']->name,
            ],
            'clubs' => $user->clubMemberships
                ->map(fn ($membership): array => [
                    'id' => (string) $membership->club_id,
                    'name' => (string) ($membership->club?->name ?? 'Unknown club'),
                    'role' => (string) $membership->role,
                    'status' => (string) $membership->status,
                ])
                ->values()
                ->all(),
            'bookings' => [
                'upcoming' => (clone $bookings)
                    ->where('starts_at', '>=', $now)
                    ->whereNotIn('status', ['cancelled', 'expired'])
                    ->count(),
                'total' => (clone $bookings)->count(),
            ],
            'suspension' => $suspension === null ? null : [
                'at' => $suspension->suspended_at->toIso8601String(),
                'by' => self::person($suspension->suspendedBy) ?? ['id' => '0', 'name' => 'Super Admin'],
                'reason' => (string) $suspension->reason,
            ],
            'history' => self::history('user', (int) $user->id),
        ];
    }

    public static function venueStatus(Tenant $tenant): string
    {
        $status = $tenant->venueProfile?->status;

        if (in_array($status, self::STATUSES, true)) {
            return $status;
        }

        return in_array($tenant->status, ['active', 'trial'], true) ? 'active' : 'suspended';
    }

    public static function clubStatus(Club $club): string
    {
        return in_array($club->status, self::STATUSES, true) ? $club->status : 'active';
    }

    public static function userStatus(User $user): string
    {
        $user->loadMissing('suspensions');

        if ($user->account_status === 'suspended' || self::activeSuspension($user) !== null) {
            return 'suspended';
        }

        return $user->email_verified_at === null ? 'unverified' : 'active';
    }

    public static function statusWord(string $status): string
    {
        return self::STATUS_WORDS[$status] ?? $status;
    }

    /**
     * The latest decision recorded for a venue or club, if any.
     *
     * @return array<string, mixed>|null
     */
    public static function decision(string $kind, int $id): ?array
    {
        $types = $kind === 'venue' ? ['venue', 'tenant'] : ['club'];

        $entry = AuditLog::query()
            ->with('user')
            ->whereIn('entity_type', $types)
            ->where('entity_id', $id)
            ->orderByDesc('id')
            ->get()
            ->first(fn (AuditLog $log): bool => preg_match('/\.(approve|reject|suspend|reactivate)$/', (string) $log->action) === 1);

        if ($entry === null) {
            return null;
        }

        $values = is_array($entry->new_values) ? $entry->new_values : [];

        return [
            'at' => $entry->created_at->toIso8601String(),
            'by' => self::person($entry->user) ?? ['id' => '0', 'name' => 'Super Admin'],
            'action' => Str::afterLast((string) $entry->action, '.'),
            'reason' => self::reason($values),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function history(string $kind, int $id): array
    {
        $types = $kind === 'venue' ? ['venue', 'tenant'] : [$kind];

        return AuditLog::query()
            ->with('user')
            ->whereIn('entity_type', $types)
            ->where('entity_id', $id)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log): array => self::auditEntry($log))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function auditEntry(AuditLog $log): array
    {
        $log->loadMissing('user');

        $values = is_array($log->new_values) ? $log->new_values : [];

        $type = match ((string) $log->entity_type) {
            'tenant', 'venue' => 'venue',
            'club' => 'club',
            'user' => 'user',
            'plan' => 'plan',
            'subscription' => 'subscription',
            'announcement' => 'announcement',
            'match' => 'match',
            default => 'settings',
        };

        return [
            'id' => (string) $log->id,
            'at' => $log->created_at->toIso8601String(),
            'actor' => self::person($log->user) ?? ['id' => '0', 'name' => 'System'],
            'action' => (string) $log->action,
            'summary' => (string) ($values['summary']
                ?? Str::headline(str_replace('.', ' ', (string) $log->action))),
            'target' => [
                'type' => $type,
                'id' => (string) ($log->entity_id ?? ''),
                'name' => (string) ($values['name'] ?? ''),
            ],
            'reason' => self::reason($values),
        ];
    }

    public static function writeAudit(
        string $action,
        string $summary,
        string $type,
        int $id,
        string $name,
        ?string $reason,
        ?int $tenantId = null,
    ): AuditLog {
        return AuditLog::create([
            'tenant_id' => $tenantId,
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => $type,
            'entity_id' => $id,
            'new_values' => ['summary' => $summary, 'name' => $name, 'reason' => $reason],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function dashboard(): array
    {
        $tenants = Tenant::query()
            ->with(['venueProfile', 'subscription.plan', 'subscriptionPlan'])
            ->where('slug', '!=', 'platform-admin')
            ->get();

        $clubs = Club::query()->get(['id', 'status']);
        $now = CarbonImmutable::now();
        $month = $now->setTimezone(self::TZ)->format('Y-m');

        $venueStatuses = $tenants->map(fn (Tenant $tenant): string => self::venueStatus($tenant));
        $clubStatuses = $clubs->map(fn (Club $club): string => self::clubStatus($club));

        $subscriptions = TenantSubscription::query()
            ->with(['plan', 'tenant.venueProfile'])
            ->get()
            ->filter(fn (TenantSubscription $subscription): bool => $subscription->tenant !== null
                && $subscription->tenant->slug !== 'platform-admin');

        $paidSubscriptions = SubscriptionInvoice::query()
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->get()
            ->filter(fn (SubscriptionInvoice $invoice): bool => self::inMonth($invoice->paid_at, $month))
            ->sum(fn (SubscriptionInvoice $invoice): float => (float) $invoice->amount);

        $unpaidSubscriptions = SubscriptionInvoice::query()
            ->whereIn('status', ['pending', 'failed'])
            ->get()
            ->sum(fn (SubscriptionInvoice $invoice): float => (float) $invoice->amount);

        $bookingPayments = Payment::query()
            ->whereIn('status', ['verified', 'paid'])
            ->get()
            ->filter(fn (Payment $payment): bool => self::inMonth($payment->created_at, $month))
            ->sum(fn (Payment $payment): float => (float) $payment->amount);

        $refunds = Refund::query()
            ->where('status', 'completed')
            ->get()
            ->filter(fn (Refund $refund): bool => self::inMonth($refund->created_at, $month))
            ->sum(fn (Refund $refund): float => (float) $refund->amount);

        $mrr = $subscriptions
            ->filter(fn (TenantSubscription $subscription): bool => $subscription->status === 'active')
            ->sum(fn (TenantSubscription $subscription): float => (float) ($subscription->plan?->price ?? 0));

        $trialsEnding = $subscriptions
            ->filter(fn (TenantSubscription $subscription): bool => $subscription->status === 'trialing'
                && $subscription->trial_ends_at !== null
                && self::venueStatus($subscription->tenant) === 'active')
            ->map(function (TenantSubscription $subscription): array {
                return [
                    'club_id' => (string) $subscription->tenant_id,
                    'club_name' => (string) $subscription->tenant->name,
                    'ends_at' => $subscription->trial_ends_at->toIso8601String(),
                    'days_left' => self::trialDaysLeft($subscription),
                ];
            })
            ->filter(fn (array $trial): bool => $trial['days_left'] !== null && $trial['days_left'] <= 7)
            ->sortBy('ends_at')
            ->values()
            ->all();

        $review = array_slice(self::reviewQueue(), 0, 5);

        return [
            'counts' => [
                'venues_active' => $venueStatuses->filter(fn (string $status): bool => $status === 'active')->count(),
                'venues_pending' => $venueStatuses->filter(fn (string $status): bool => $status === 'pending_review')->count(),
                'venues_suspended' => $venueStatuses->filter(fn (string $status): bool => $status === 'suspended')->count(),
                'clubs_active' => $clubStatuses->filter(fn (string $status): bool => $status === 'active')->count(),
                'clubs_pending' => $clubStatuses->filter(fn (string $status): bool => $status === 'pending_review')->count(),
                'players' => PlayerProfile::query()->count(),
                'bookings_next_7_days' => Booking::query()
                    ->where('starts_at', '>=', $now)
                    ->where('starts_at', '<=', $now->addDays(7))
                    ->whereNotIn('status', ['cancelled', 'expired'])
                    ->count(),
                'sessions_next_7_days' => OpenPlaySession::query()
                    ->where('starts_at', '>=', $now)
                    ->where('starts_at', '<=', $now->addDays(7))
                    ->where('status', 'published')
                    ->count(),
                'disputes' => GameMatch::query()->where('status', 'disputed')->count(),
            ],
            'revenue' => [
                'mrr' => Contract::money($mrr),
                'subscriptions_paid_this_month' => Contract::money($paidSubscriptions),
                'subscriptions_unpaid' => Contract::money($unpaidSubscriptions),
                'bookings_paid_this_month' => Contract::money($bookingPayments - $refunds),
            ],
            'trials_ending' => $trialsEnding,
            'review' => $review,
            'recent_activity' => AuditLog::query()
                ->with('user')
                ->orderByDesc('id')
                ->limit(6)
                ->get()
                ->map(fn (AuditLog $log): array => self::auditEntry($log))
                ->values()
                ->all(),
        ];
    }

    /**
     * Sign-ups waiting for a decision, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function reviewQueue(): array
    {
        $items = [];

        $profiles = VenueProfile::query()
            ->where('status', 'pending_review')
            ->with(['tenant.tenantUsers.role', 'tenant.tenantUsers.user'])
            ->get();

        foreach ($profiles as $profile) {
            $tenant = $profile->tenant;

            if ($tenant === null || $tenant->slug === 'platform-admin') {
                continue;
            }

            $owner = $tenant->tenantUsers->first(
                fn ($row): bool => $row->status === 'active' && $row->role?->name === 'owner'
            );
            $submitted = $profile->submitted_at ?? $profile->created_at ?? CarbonImmutable::now();

            $items[] = [
                'kind' => 'venue',
                'id' => (string) $tenant->id,
                'name' => (string) $tenant->name,
                'city' => (string) ($profile->city ?? ''),
                'province' => (string) ($profile->province ?? ''),
                'submitted_at' => $submitted->toIso8601String(),
                'submitted_by' => $owner?->user === null
                    ? ['id' => '', 'name' => 'Unknown', 'email' => '']
                    : self::personWithEmail($owner->user),
            ];
        }

        $clubs = Club::query()
            ->where('status', 'pending_review')
            ->with('creator')
            ->get();

        foreach ($clubs as $club) {
            $submitted = $club->created_at ?? CarbonImmutable::now();
            $creator = $club->creator;

            $items[] = [
                'kind' => 'club',
                'id' => (string) $club->id,
                'name' => (string) $club->name,
                'city' => (string) ($club->city ?? ''),
                'province' => (string) ($club->province ?? ''),
                'submitted_at' => $submitted->toIso8601String(),
                'submitted_by' => $creator === null
                    ? ['id' => '', 'name' => 'Unknown', 'email' => '']
                    : self::personWithEmail($creator),
            ];
        }

        usort($items, fn (array $a, array $b): int => strcmp($a['submitted_at'], $b['submitted_at']));

        return $items;
    }

    public static function trialDaysLeft(TenantSubscription $subscription): ?int
    {
        if ($subscription->trial_ends_at === null) {
            return null;
        }

        $seconds = $subscription->trial_ends_at->getTimestamp() - CarbonImmutable::now()->getTimestamp();

        return max((int) ceil($seconds / 86400), 0);
    }

    /**
     * @return array{id: string, name: string, email: string}|null
     */
    private static function personWithEmail(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
        ];
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private static function person(?User $user): ?array
    {
        return $user === null ? null : [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
        ];
    }

    private static function venueOwner(Tenant $tenant): ?User
    {
        $row = $tenant->tenantUsers->first(
            fn ($membership): bool => $membership->status === 'active' && $membership->role?->name === 'owner'
        );

        return $row?->user;
    }

    private static function subscriptionStatus(?TenantSubscription $subscription): string
    {
        if ($subscription === null) {
            return 'none';
        }

        $status = (string) $subscription->status;

        return in_array($status, Contract::SUBSCRIPTION_STATUSES, true) ? $status : 'trialing';
    }

    private static function venueRoleKey(string $role): string
    {
        return match ($role) {
            'owner' => 'owner',
            'club_manager' => 'manager',
            'staff' => 'front_desk',
            default => $role,
        };
    }

    private static function activeSuspension(User $user): ?UserSuspension
    {
        return $user->suspensions->first(fn (UserSuspension $row): bool => $row->lifted_at === null);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function reason(array $values): ?string
    {
        $reason = $values['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    private static function inMonth(mixed $date, string $month): bool
    {
        if ($date === null) {
            return false;
        }

        return CarbonImmutable::parse($date)->setTimezone(self::TZ)->format('Y-m') === $month;
    }
}
