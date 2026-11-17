<?php

namespace App\Console\Commands;

use App\Models\SubscriptionInvoice;
use App\Models\TenantSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateRenewalInvoicesCommand extends Command
{
    protected $signature = 'subscriptions:generate-renewals';

    protected $description = 'Create renewal invoices for subscriptions ending within 7 days';

    public function handle(): int
    {
        $subscriptions = TenantSubscription::with('plan')
            ->whereIn('status', ['active', 'trialing'])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now()->addDays(7))
            ->where('current_period_end', '>', now())
            ->get();

        $generated = 0;

        foreach ($subscriptions as $subscription) {
            $existingInvoice = SubscriptionInvoice::where('tenant_subscription_id', $subscription->id)
                ->where('status', 'pending')
                ->where('due_at', '>=', now())
                ->exists();

            if ($existingInvoice) {
                continue;
            }

            $interval = $subscription->pending_billing_interval ?? $subscription->billing_interval;
            $plan = $subscription->plan;

            if (! $plan) {
                continue;
            }

            $amount = $interval === 'yearly'
                ? ($plan->yearly_price ?? $plan->price)
                : $plan->price;

            SubscriptionInvoice::create([
                'tenant_id' => $subscription->tenant_id,
                'tenant_subscription_id' => $subscription->id,
                'invoice_number' => 'INV-'.strtoupper(Str::random(10)),
                'amount' => $amount,
                'status' => 'pending',
                'due_at' => $subscription->current_period_end,
            ]);

            $generated++;
        }

        $this->info("Generated {$generated} renewal invoice(s).");

        return self::SUCCESS;
    }
}
