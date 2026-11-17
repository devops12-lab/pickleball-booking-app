<?php

namespace App\Console\Commands;

use App\Models\TenantSubscription;
use Illuminate\Console\Command;

class ProcessPendingDowngradesCommand extends Command
{
    protected $signature = 'subscriptions:process-downgrades';

    protected $description = 'Apply pending plan downgrades and billing interval changes at period end';

    public function handle(): int
    {
        $subscriptions = TenantSubscription::with('plan')
            ->whereIn('status', ['active', 'cancelled'])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->where(function ($q): void {
                $q->whereNotNull('pending_plan_id')
                    ->orWhereNotNull('pending_billing_interval');
            })
            ->get();

        $processed = 0;

        foreach ($subscriptions as $subscription) {
            $updates = [];

            if ($subscription->pending_plan_id) {
                $updates['subscription_plan_id'] = $subscription->pending_plan_id;
                $updates['plan_snapshot'] = null;
                $updates['pending_plan_id'] = null;

                $subscription->tenant?->update(['subscription_plan_id' => $subscription->pending_plan_id]);
            }

            if ($subscription->pending_billing_interval) {
                $updates['billing_interval'] = $subscription->pending_billing_interval;
                $updates['pending_billing_interval'] = null;
            }

            if (! empty($updates)) {
                $subscription->update($updates);
                $processed++;
            }
        }

        $this->info("Processed {$processed} pending change(s).");

        return self::SUCCESS;
    }
}
