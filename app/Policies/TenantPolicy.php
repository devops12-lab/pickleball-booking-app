<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
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
        return false;
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->isOwnerOf($tenant->id) || $user->isStaffOf($tenant->id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->isOwnerOf($tenant->id);
    }

    public function updateStatus(User $user, Tenant $tenant): bool
    {
        return false;
    }
}
