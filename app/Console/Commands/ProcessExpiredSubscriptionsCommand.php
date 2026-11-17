<?php

namespace App\Console\Commands;

use App\Models\TenantSubscription;
use Illuminate\Console\Command;

class ProcessExpiredSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:process-expiration';

    protected $description = 'Move past_due subscriptions to expired after grace period (7 days)';

    public function handle(): int
    {
        $expired = TenantSubscription::where('status', 'past_due')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now()->subDays(7))
            ->update(['status' => 'expired']);

        $cancelledExpired = TenantSubscription::where('status', 'cancelled')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->update(['status' => 'expired']);

        $total = $expired + $cancelledExpired;
        $this->info("Expired {$total} subscription(s) ({$expired} past_due, {$cancelledExpired} cancelled).");

        return self::SUCCESS;
    }
}
