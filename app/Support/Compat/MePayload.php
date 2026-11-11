<?php

namespace App\Support\Compat;

use App\Models\PlayerProfile;
use App\Models\TenantUser;
use App\Models\User;

/**
 * The `Me` object from packages/api/src/types.ts, built from this backend's
 * tables.
 *
 * The SPA's "club" is a venue tenant, so memberships come from tenant_users
 * (not club_members, which are community groups) and the permission list is the
 * one the SPA uses to decide which buttons to show. Real enforcement stays on
 * the role-scoped routes server-side.
 */
class MePayload
{
    /**
     * @var list<string>
     */
    public const OWNER_PERMISSIONS = [
        'viewClub',
        'updateClub',
        'manageCourts',
        'manageCourtSchedules',
        'viewBookings',
        'createWalkInBooking',
        'recordOnsitePayment',
        'adjustOnsitePayment',
        'cancelBooking',
        'manageOpenPlay',
        'approvePlayerOpenPlay',
        'checkInOpenPlay',
        'manageClubMembers',
        'manageClubStaff',
        'viewClubReports',
        'manageClubSubscription',
    ];

    /**
     * Front desk and court staff: run the day, but not the venue's money or people.
     *
     * @var list<string>
     */
    public const STAFF_PERMISSIONS = [
        'viewClub',
        'viewBookings',
        'createWalkInBooking',
        'recordOnsitePayment',
        'cancelBooking',
        'manageOpenPlay',
        'approvePlayerOpenPlay',
        'checkInOpenPlay',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function for(User $user): array
    {
        $rows = TenantUser::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->with(['tenant', 'role'])
            ->get()
            ->filter(fn (TenantUser $row) => $row->tenant !== null && $row->role !== null);

        $isPlatformAdmin = $rows->contains(fn (TenantUser $row) => $row->tenant->slug === 'platform-admin');

        $memberships = $rows
            ->filter(fn (TenantUser $row) => $row->tenant->slug !== 'platform-admin')
            ->map(function (TenantUser $row): ?array {
                $roleKey = match ($row->role->name) {
                    'owner' => 'owner',
                    'club_manager' => 'manager',
                    // This backend has one staff role; the SPA's default desk role fits it.
                    'staff' => 'front_desk',
                    default => null,
                };

                if ($roleKey === null) {
                    return null;
                }

                return [
                    'club_id' => (string) $row->tenant_id,
                    'club_slug' => $row->tenant->slug,
                    'club_name' => $row->tenant->name,
                    'role_key' => $roleKey,
                    'permissions' => $roleKey === 'front_desk'
                        ? self::STAFF_PERMISSIONS
                        : self::OWNER_PERMISSIONS,
                ];
            })
            ->filter()
            ->values()
            ->all();

        $hasProfile = PlayerProfile::query()->where('user_id', $user->id)->exists();

        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'global_roles' => $isPlatformAdmin ? ['super_admin'] : [],
            'has_player_profile' => $hasProfile,
            'email_verified' => $user->email_verified_at !== null,
            // Venue staff don't go through the player onboarding flow.
            'needs_onboarding' => ! $hasProfile && $memberships === [] && ! $isPlatformAdmin,
            'memberships' => $memberships,
        ];
    }
}
