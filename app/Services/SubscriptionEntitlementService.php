<?php

namespace App\Services;

use App\Exceptions\SubscriptionLimitExceededException;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Illuminate\Support\Facades\DB;

class SubscriptionEntitlementService
{
    /**
     * Statuses that allow resource creation.
     *
     * @var list<string>
     */
    private const CREATION_ALLOWED_STATUSES = ['active', 'trialing', 'cancelled'];

    /**
     * Check whether the tenant can create another resource of the given type.
     * Returns true when creation is allowed, false otherwise.
     */
    public function canCreate(Tenant $tenant, string $resourceType): bool
    {
        $subscription = $this->activeSubscription($tenant);

        if (! $subscription) {
            return false;
        }

        if (! in_array($subscription->status, self::CREATION_ALLOWED_STATUSES, true)) {
            return false;
        }

        $limit = $this->limitFor($subscription, $resourceType);

        if ($limit === null) {
            return true;
        }

        return $this->currentCount($tenant, $resourceType) < $limit;
    }

    /**
     * Assert that the tenant can create another resource of the given type.
     * Uses SELECT … FOR UPDATE inside a transaction to prevent race conditions.
     *
     * @throws SubscriptionLimitExceededException
     */
    public function checkOrFail(Tenant $tenant, string $resourceType): void
    {
        DB::transaction(function () use ($tenant, $resourceType): void {
            $lockedTenant = Tenant::lockForUpdate()->find($tenant->id);
            $subscription = $this->activeSubscription($lockedTenant);

            if (! $subscription) {
                throw new SubscriptionLimitExceededException($resourceType, 0, 0);
            }

            if (! in_array($subscription->status, self::CREATION_ALLOWED_STATUSES, true)) {
                throw new SubscriptionLimitExceededException(
                    $resourceType,
                    $this->currentCount($lockedTenant, $resourceType),
                    0
                );
            }

            $limit = $this->limitFor($subscription, $resourceType);

            if ($limit === null) {
                return;
            }

            $count = $this->currentCount($lockedTenant, $resourceType);

            if ($count >= $limit) {
                throw new SubscriptionLimitExceededException($resourceType, $count, $limit);
            }
        });
    }

    /**
     * Get current resource usage for the tenant.
     *
     * @return array<string, array{current: int, limit: int|null, remaining: int|null}>
     */
    public function usage(Tenant $tenant): array
    {
        $subscription = $this->activeSubscription($tenant);
        $resources = ['court', 'staff'];
        $result = [];

        foreach ($resources as $resource) {
            $current = $this->currentCount($tenant, $resource);
            $limit = $subscription ? $this->limitFor($subscription, $resource) : 0;
            $result[$resource] = [
                'current' => $current,
                'limit' => $limit,
                'remaining' => $limit !== null ? max(0, $limit - $current) : null,
            ];
        }

        return $result;
    }

    /**
     * Check whether the tenant's plan includes a specific feature.
     */
    public function hasFeature(Tenant $tenant, string $featureKey): bool
    {
        $subscription = $this->activeSubscription($tenant);

        if (! $subscription) {
            return false;
        }

        $features = $subscription->plan?->features ?? [];

        return in_array($featureKey, $features, true);
    }

    /**
     * Check whether the tenant has an active (creation-allowed) subscription.
     */
    public function isSubscriptionActive(Tenant $tenant): bool
    {
        $subscription = $this->activeSubscription($tenant);

        return $subscription !== null
            && in_array($subscription->status, self::CREATION_ALLOWED_STATUSES, true);
    }

    private function activeSubscription(Tenant $tenant): ?TenantSubscription
    {
        return $tenant->subscription()->with('plan')->first();
    }

    private function limitFor(TenantSubscription $subscription, string $resourceType): ?int
    {
        return match ($resourceType) {
            'court' => $subscription->plan?->max_courts,
            'staff' => $subscription->plan?->max_staff,
            default => null,
        };
    }

    private function currentCount(Tenant $tenant, string $resourceType): int
    {
        return match ($resourceType) {
            'court' => $tenant->courts()->count(),
            'staff' => $tenant->tenantUsers()
                ->where('status', 'active')
                ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'owner']))
                ->count(),
            default => 0,
        };
    }
}
