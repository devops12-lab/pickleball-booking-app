<?php

namespace App\Policies;

use App\Models\Facility;
use App\Models\User;

class FacilityPolicy
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

    public function view(User $user, Facility $facility): bool
    {
        if (! $facility->is_active && ! $user->isOwnerOf($facility->tenant_id) && ! $user->isStaffOf($facility->tenant_id)) {
            return false;
        }

        return true;
    }

    public function create(User $user, ?int $tenantId = null): bool
    {
        if ($tenantId === null) {
            return false;
        }

        return $user->isOwnerOf($tenantId) || $user->hasPermission('facilities.create', $tenantId);
    }

    public function update(User $user, Facility $facility): bool
    {
        return $user->isOwnerOf($facility->tenant_id) || $user->hasPermission('facilities.edit', $facility->tenant_id);
    }

    public function delete(User $user, Facility $facility): bool
    {
        return $user->isOwnerOf($facility->tenant_id);
    }
}
