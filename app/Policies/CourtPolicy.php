<?php

namespace App\Policies;

use App\Models\Court;
use App\Models\User;

class CourtPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSystemAdmin()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, Court $court): bool
    {
        if (! $court->is_active && ! $user->isOwnerOf($court->tenant_id) && ! $user->isStaffOf($court->tenant_id)) {
            return false;
        }

        return true;
    }

    public function create(User $user, ?int $tenantId = null): bool
    {
        if ($tenantId === null) {
            return false;
        }

        return $user->isOwnerOf($tenantId) || $user->hasPermission('courts.create', $tenantId);
    }

    public function update(User $user, Court $court): bool
    {
        return $user->isOwnerOf($court->tenant_id) || $user->hasPermission('courts.edit', $court->tenant_id);
    }

    public function delete(User $user, Court $court): bool
    {
        return $user->isOwnerOf($court->tenant_id);
    }
}
