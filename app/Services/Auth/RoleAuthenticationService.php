<?php

namespace App\Services\Auth;

use App\Models\ClubMember;
use App\Models\User;

class RoleAuthenticationService
{
    /**
     * Determine if a user is eligible to authenticate into the requested role portal.
     */
    public function canAuthenticateAs(User $user, string $role, ?int $tenantId = null): bool
    {
        $normalized = str_replace('-', '_', strtolower($role));

        if (! $user->isActive()) {
            return false;
        }

        return match ($normalized) {
            'system_admin' => $user->isSystemAdmin(),
            'owner' => $this->canAuthenticateAsOwner($user, $tenantId),
            'staff' => $this->canAuthenticateAsStaff($user, $tenantId),
            'player' => true, // All active registered users are players globally
            'club_manager', 'organizer' => $this->canAuthenticateAsOrganizer($user, $tenantId),
            default => false,
        };
    }

    protected function canAuthenticateAsOwner(User $user, ?int $tenantId = null): bool
    {
        return $user->tenantUsers()
            ->whereHas('role', fn ($q) => $q->where('name', 'owner'))
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', 'active')
            ->exists();
    }

    protected function canAuthenticateAsStaff(User $user, ?int $tenantId = null): bool
    {
        return $user->tenantUsers()
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'owner']))
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', 'active')
            ->exists();
    }

    protected function canAuthenticateAsOrganizer(User $user, ?int $tenantId = null): bool
    {
        if ($user->isSystemAdmin()) {
            return true;
        }

        // Check if user has club_manager role in a tenant
        $hasTenantRole = $user->tenantUsers()
            ->whereHas('role', fn ($q) => $q->where('name', 'club_manager'))
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', 'active')
            ->exists();

        if ($hasTenantRole) {
            return true;
        }

        // Check if user is an active manager/organizer in any club
        return ClubMember::where('user_id', $user->id)
            ->whereIn('role', ['manager', 'organizer'])
            ->where('status', 'active')
            ->exists();
    }
}
