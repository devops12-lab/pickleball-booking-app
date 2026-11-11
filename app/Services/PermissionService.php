<?php

namespace App\Services;

use App\Models\User;

class PermissionService
{
    public function __construct(
        protected TenantContextService $tenantContext
    ) {}

    /**
     * Determine if a user has a specific permission in the active tenant or globally.
     */
    public function can(User $user, string $permission, ?int $tenantId = null): bool
    {
        $targetTenantId = $tenantId ?? $this->tenantContext->getTenantId();

        return $user->hasPermission($permission, $targetTenantId);
    }

    /**
     * Check if a user belongs to a tenant with an active status.
     */
    public function hasTenantAccess(User $user, int $tenantId): bool
    {
        if ($user->isSystemAdmin()) {
            return true;
        }

        return $user->tenantUsers()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->exists();
    }
}
