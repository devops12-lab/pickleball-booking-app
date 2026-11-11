<?php

namespace App\Support\Compat;

use App\Models\Club;
use App\Models\ClubMember;
use App\Models\OpenPlayQueueGame;
use App\Models\OpenPlaySession;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The SPA's club directory and club profile (packages/api/src/open-play-types.ts).
 *
 * A venue listing is a tenant; a community listing is a `clubs` row. Both share
 * one shape, so the directory can mix tabs without special cases.
 *
 * Membership plans and the viewer's own membership come from the membership
 * tables; public profiles never expose the member list or dues ledger.
 */
class CommunityView
{
    public const ACTIVE_SESSION_STATUSES = ['published', 'scheduled', 'live', 'in_progress'];

    /**
     * @return array<string, mixed>
     */
    public static function venueListing(Tenant $tenant): array
    {
        $tenant->loadMissing(['venueProfile', 'subscription', 'courts']);

        $club = Contract::club($tenant);
        $settings = $club['settings'];
        $courts = $tenant->courts->filter(fn ($court): bool => Contract::court($court)['status'] === 'active');

        [$upcoming, $live] = self::sessionCounts(
            OpenPlaySession::query()->where('tenant_id', $tenant->id),
        );

        return [
            'type' => 'venue',
            'id' => (string) $tenant->id,
            'slug' => (string) $tenant->slug,
            'name' => (string) $tenant->name,
            'tagline' => (string) $club['tagline'],
            'city' => (string) $club['city'],
            'province' => (string) $club['province'],
            'logo_url' => $club['logo_url'],
            'cover_url' => $club['cover_url'],
            'status' => $club['status'],
            'member_count' => DB::table('tenant_users')->where('tenant_id', $tenant->id)->count(),
            'court_count' => $courts->count(),
            'upcoming_session_count' => $upcoming,
            'live_session_count' => $live,
            'home_venue' => null,
            'accepts_community_sessions' => (bool) ($settings['allow_player_open_play'] ?? true)
                && ! $club['read_only'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function communityListing(Club $club): array
    {
        $club->loadMissing('homeVenue');
        $home = $club->homeVenue;

        [$upcoming, $live] = self::sessionCounts(
            OpenPlaySession::query()
                ->where('organizer_type', 'community')
                ->where('organizer_id', $club->id),
        );

        return [
            'type' => 'community',
            'id' => (string) $club->id,
            'slug' => (string) $club->slug,
            'name' => (string) $club->name,
            'tagline' => (string) ($club->tagline ?? ''),
            'city' => (string) ($club->city ?? ''),
            'province' => (string) ($club->province ?? ''),
            'logo_url' => $club->logo ?: null,
            'cover_url' => $club->cover ?: null,
            'status' => self::communityStatus($club),
            'member_count' => DB::table('club_members')
                ->where('club_id', $club->id)
                ->where('status', 'active')
                ->count(),
            'court_count' => null,
            'upcoming_session_count' => $upcoming,
            'live_session_count' => $live,
            'home_venue' => $home === null ? null : [
                'id' => (string) $home->id,
                'slug' => (string) $home->slug,
                'name' => (string) $home->name,
            ],
            'accepts_community_sessions' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function venueProfile(Tenant $tenant, ?int $viewerId): array
    {
        $tenant->loadMissing(['venueProfile', 'subscription', 'courts']);

        $club = Contract::club($tenant);
        $settings = $club['settings'];
        $isStaff = $viewerId !== null && DB::table('tenant_users')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $viewerId)
            ->exists();

        return self::venueListing($tenant) + [
            'description' => (string) $club['description'],
            'rules' => (string) $club['rules'],
            'contact_email' => (string) $club['contact_email'],
            'contact_phone' => (string) $club['contact_phone'],
            'join_policy' => in_array($settings['member_join_policy'] ?? 'open', ['open', 'approval'], true)
                ? $settings['member_join_policy']
                : 'open',
            'cancellation_hours' => (int) ($settings['open_play_cancellation_hours'] ?? 12),
            'created_at' => ($tenant->created_at ?? now())->toIso8601String(),
            'plans' => [],
            'waiver' => '',
            'payment_options' => self::emptyPaymentOptions(),
            'me' => [
                'membership' => null,
                'role' => $isStaff ? 'staff' : null,
                'can_manage' => $isStaff,
            ],
            'stats' => self::stats(OpenPlaySession::query()->where('tenant_id', $tenant->id)),
            'courts' => $tenant->courts
                ->filter(fn ($court): bool => Contract::court($court)['status'] === 'active')
                ->map(fn ($court): array => [
                    'id' => (string) $court->id,
                    'name' => (string) $court->name,
                    'indoor_outdoor' => $court->court_type === 'outdoor' ? 'outdoor' : 'indoor',
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function communityProfile(Club $club, ?int $viewerId): array
    {
        $club->loadMissing('membershipPlans');

        $membership = $viewerId === null ? null : ClubMember::query()
            ->with(['plan', 'payments'])
            ->where('club_id', $club->id)
            ->where('user_id', $viewerId)
            ->first();

        $role = $membership === null ? null : self::memberRole((string) $membership->role);
        $status = $membership === null ? null : MembershipView::status($membership);

        return self::communityListing($club) + [
            'description' => (string) ($club->description ?? ''),
            'rules' => (string) ($club->rules ?? ''),
            'contact_email' => (string) ($club->contact_email ?? ''),
            'contact_phone' => (string) ($club->contact_phone ?? ''),
            'join_policy' => in_array($club->join_policy, ['open', 'approval'], true) ? $club->join_policy : 'open',
            'cancellation_hours' => (int) ($club->cancellation_hours ?? 12),
            'created_at' => ($club->created_at ?? now())->toIso8601String(),
            'plans' => $club->membershipPlans
                ->filter(fn ($plan): bool => (bool) $plan->is_active
                    || ($viewerId !== null && self::canManageCommunity($club, $viewerId)))
                ->map(fn ($plan): array => MembershipView::plan($plan))
                ->values()
                ->all(),
            'waiver' => (string) ($club->waiver ?? ''),
            'payment_options' => self::paymentOptions($club),
            'me' => [
                'membership' => $membership === null ? null : self::memberSummary($membership),
                'role' => $status === 'active' ? $role : null,
                'can_manage' => self::canManageCommunity($club, $viewerId),
            ],
            'stats' => self::stats(
                OpenPlaySession::query()
                    ->where('organizer_type', 'community')
                    ->where('organizer_id', $club->id),
            ),
            'courts' => [],
        ];
    }

    /**
     * One membership as the SPA's MemberSummary.
     *
     * @return array<string, mixed>
     */
    public static function memberSummary(ClubMember $membership): array
    {
        return MembershipView::summary($membership);
    }

    public static function canManageCommunity(Club $club, ?int $viewerId): bool
    {
        if ($viewerId === null) {
            return false;
        }

        if ((int) $club->creator_id === $viewerId) {
            return true;
        }

        return DB::table('club_members')
            ->where('club_id', $club->id)
            ->where('user_id', $viewerId)
            ->whereIn('role', ['owner', 'manager', 'organizer'])
            ->where('status', 'active')
            ->exists();
    }

    /**
     * @param  Builder<OpenPlaySession>  $query
     * @return array{0: int, 1: int}
     */
    private static function sessionCounts(Builder $query): array
    {
        $active = (clone $query)
            ->whereIn('status', self::ACTIVE_SESSION_STATUSES)
            ->where('ends_at', '>', now());

        $upcoming = (clone $active)->count();
        $live = (clone $active)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->count();

        return [$upcoming, $live];
    }

    /**
     * Completed sessions, the distinct players in them, and finished queue games.
     *
     * @param  Builder<OpenPlaySession>  $query
     * @return array{sessions_held: int, players_hosted: int, games_played: int}
     */
    private static function stats(Builder $query): array
    {
        $ids = (clone $query)
            ->where(function (Builder $q): void {
                $q->where('status', 'completed')
                    ->orWhere(function (Builder $q): void {
                        $q->whereIn('status', ['published', 'scheduled'])
                            ->where('ends_at', '<=', now());
                    });
            })
            ->pluck('id');

        $players = $ids->isEmpty()
            ? 0
            : DB::table('open_play_participants')
                ->whereIn('open_play_session_id', $ids)
                ->whereIn('status', ['completed', 'checked_in'])
                ->distinct()
                ->count('user_id');

        $games = $ids->isEmpty()
            ? 0
            : OpenPlayQueueGame::query()
                ->whereIn('open_play_session_id', $ids)
                ->whereNotNull('ended_at')
                ->count();

        return [
            'sessions_held' => $ids->count(),
            'players_hosted' => $players,
            'games_played' => $games,
        ];
    }

    private static function communityStatus(Club $club): string
    {
        return in_array($club->status, ['pending_review', 'active', 'suspended', 'rejected'], true)
            ? $club->status
            : 'active';
    }

    private static function memberRole(string $role): string
    {
        return match ($role) {
            'owner' => 'owner',
            'manager', 'organizer', 'admin' => 'admin',
            default => 'member',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function paymentOptions(Club $club): array
    {
        $stored = is_array($club->payment_options) ? $club->payment_options : [];

        return [
            'in_person' => (bool) ($stored['in_person'] ?? false),
            'gcash' => self::wallet($stored['gcash'] ?? null),
            'maya' => self::wallet($stored['maya'] ?? null),
            'instructions' => (string) ($stored['instructions'] ?? ''),
        ];
    }

    /**
     * @return array{account_name: string, account_number: string, qr_url: string|null}|null
     */
    private static function wallet(mixed $wallet): ?array
    {
        if (! is_array($wallet) || empty($wallet['account_number'])) {
            return null;
        }

        return [
            'account_name' => (string) ($wallet['account_name'] ?? ''),
            'account_number' => (string) $wallet['account_number'],
            'qr_url' => $wallet['qr_url'] ?? null,
        ];
    }

    /**
     * @return array{in_person: bool, gcash: null, maya: null, instructions: string}
     */
    private static function emptyPaymentOptions(): array
    {
        return ['in_person' => false, 'gcash' => null, 'maya' => null, 'instructions' => ''];
    }
}
