<?php

namespace App\Services;

use App\Models\Tenant;

class TenantContextService
{
    protected ?Tenant $currentTenant = null;

    /**
     * Set the current active tenant.
     */
    public function setTenant(?Tenant $tenant): void
    {
        $this->currentTenant = $tenant;
    }

    /**
     * Get the current active tenant.
     */
    public function getTenant(): ?Tenant
    {
        return $this->currentTenant;
    }

    /**
     * Get the current active tenant ID.
     */
    public function getTenantId(): ?int
    {
        return $this->currentTenant?->id;
    }

    /**
     * Check if a tenant context is currently set.
     */
    public function hasTenant(): bool
    {
        return $this->currentTenant !== null;
    }

    /**
     * Clear the current tenant context.
     */
    public function clear(): void
    {
        $this->currentTenant = null;
    }
}
