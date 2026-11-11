<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Illuminate\Database\Seeder;

/**
 * The demo venue had no subscription row, so the SPA's subscription page had
 * nothing to show. This gives it a Starter trial, the same way DemoDataSeeder
 * gives it courts and a facility.
 */
class DemoSubscriptionSeeder extends Seeder
{
    public function run(): void
    {
        $plan = SubscriptionPlan::where('slug', 'starter')->first();

        if (! $plan) {
            return;
        }

        $tenants = Tenant::query()->where('slug', 'metro-pickleball-club')->get();

        foreach ($tenants as $tenant) {
            TenantSubscription::firstOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'subscription_plan_id' => $plan->id,
                    'status' => 'trialing',
                    'starts_at' => now(),
                    'trial_ends_at' => now()->addDays(14),
                ]
            );
        }
    }
}
