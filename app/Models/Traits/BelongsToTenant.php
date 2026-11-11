<?php

namespace App\Models\Traits;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    /**
     * Get the tenant that owns this model.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Scope a query to only include records for a given tenant.
     */
    public function scopeForTenant(Builder $query, int|string $tenantId): Builder
    {
        return $query->where($this->qualifyColumn('tenant_id'), $tenantId);
    }
}
