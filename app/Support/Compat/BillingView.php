<?php

namespace App\Support\Compat;

use App\Models\Payment;
use App\Models\Refund;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Carbon\CarbonImmutable;

/**
 * The Super Admin billing contract (packages/api/src/admin-types.ts).
 *
 * Plan features have two stored shapes: older plans hold a list of feature
 * slugs and the editor now saves the contract's three booleans. Both read the
 * same way. An invoice's due date falls back to its issue date for rows that
 * predate the due_at column.
 */
class BillingView
{
    private const TZ = 'Asia/Manila';

    private const UNPAID = ['pending', 'failed', 'issued', 'overdue'];

    /**
     * @return array<string, mixed>
     */
    public static function plan(SubscriptionPlan $plan): array
    {
        return [
            'code' => (string) $plan->slug,
            'name' => (string) $plan->name,
            'price' => Contract::money($plan->price),
            // The Super Admin portal only offers monthly plans.
            'billing_interval' => 'month',
            'limits' => [
                'courts' => $plan->max_courts === null ? null : (int) $plan->max_courts,
                'staff' => $plan->max_staff === null ? null : (int) $plan->max_staff,
            ],
            'features' => self::featureSet($plan->features),
            'offered' => (bool) $plan->is_active,
            'venues' => (int) ($plan->subscriptions_count ?? $plan->subscriptions()->count()),
        ];
    }

    /**
     * @return array{open_play: bool, tournaments: bool, reports: bool}
     */
    public static function featureSet(mixed $features): array
    {
        if (! is_array($features)) {
            return ['open_play' => false, 'tournaments' => false, 'reports' => false];
        }

        if (array_is_list($features)) {
            $keys = [];
            foreach ($features as $value) {
                if (is_string($value)) {
                    $keys[] = mb_strtolower($value);
                }
            }

            return [
                'open_play' => in_array('open_play', $keys, true) || in_array('open-play', $keys, true),
                'tournaments' => in_array('tournaments', $keys, true),
                'reports' => in_array('analytics', $keys, true)
                    || in_array('reports', $keys, true)
                    || in_array('advanced_reports', $keys, true)
                    || in_array('advanced_reporting', $keys, true),
            ];
        }

        return [
            'open_play' => (bool) ($features['open_play'] ?? false),
            'tournaments' => (bool) ($features['tournaments'] ?? false),
            'reports' => (bool) ($features['reports']
                ?? $features['advanced_reports']
                ?? $features['analytics']
                ?? false),
        ];
    }

    /**
     * @return array{open_play: bool, tournaments: bool, reports: bool}
     */
    public static function featurePayload(mixed $features): array
    {
        return self::featureSet($features);
    }

    /**
     * @return array<string, mixed>
     */
    public static function subscriptionRow(TenantSubscription $subscription): array
    {
        $subscription->loadMissing([
            'tenant.tenantUsers.role',
            'tenant.tenantUsers.user',
            'plan',
            'invoices',
        ]);

        $tenant = $subscription->tenant;
        $plan = $subscription->plan;
        $status = self::subscriptionStatus($subscription->status);
        $unpaid = $subscription->invoices->filter(
            fn (SubscriptionInvoice $invoice): bool => in_array(self::invoiceStatus($invoice->status), ['issued', 'overdue'], true)
        );

        return [
            'id' => (string) $subscription->id,
            'club_id' => (string) $subscription->tenant_id,
            'club_name' => (string) ($tenant?->name ?? ''),
            'owner' => $tenant === null ? null : self::owner($tenant),
            'plan' => [
                'code' => (string) ($plan?->slug ?? ''),
                'name' => (string) ($plan?->name ?? ''),
                'price' => Contract::money($plan?->price ?? 0),
            ],
            'status' => $status,
            'trial_starts_at' => $status === 'trialing'
                ? $subscription->starts_at?->toIso8601String()
                : null,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'trial_days_left' => $status === 'trialing'
                ? AdminView::trialDaysLeft($subscription)
                : null,
            'current_period_ends_at' => $subscription->ends_at?->toIso8601String(),
            'amount_due' => Contract::money($unpaid->sum(fn (SubscriptionInvoice $invoice): float => (float) $invoice->amount)),
            'overdue' => $subscription->invoices->contains(
                fn (SubscriptionInvoice $invoice): bool => self::invoiceStatus($invoice->status) === 'overdue'
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function invoice(SubscriptionInvoice $invoice): array
    {
        $issued = $invoice->created_at ?? CarbonImmutable::now();
        $due = $invoice->due_at ?? $issued;

        return [
            'id' => (string) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'amount' => Contract::money($invoice->amount),
            'currency' => Contract::CURRENCY,
            'status' => self::invoiceStatus($invoice->status),
            'issued_at' => $issued->toIso8601String(),
            'due_at' => $due->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'payment_method' => $invoice->payment_method,
            'payment_reference' => $invoice->payment_reference,
            'void_reason' => $invoice->void_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adminInvoice(SubscriptionInvoice $invoice): array
    {
        $invoice->loadMissing(['tenant', 'subscription']);

        return self::invoice($invoice) + [
            'club_id' => (string) $invoice->tenant_id,
            'club_name' => (string) ($invoice->tenant?->name ?? ''),
            'subscription_id' => (string) ($invoice->tenant_subscription_id ?? ''),
        ];
    }

    public static function subscriptionStatus(mixed $status): string
    {
        return match ((string) $status) {
            'active' => 'active',
            'trialing' => 'trialing',
            'past_due' => 'past_due',
            'grace_period' => 'grace_period',
            'canceled', 'cancelled' => 'cancelled',
            'expired' => 'expired',
            'suspended' => 'suspended',
            default => 'trialing',
        };
    }

    public static function invoiceStatus(mixed $status): string
    {
        return match ((string) $status) {
            'paid' => 'paid',
            'void' => 'void',
            'draft' => 'draft',
            'overdue', 'failed' => 'overdue',
            'issued', 'pending' => 'issued',
            default => 'issued',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function revenue(): array
    {
        $subscriptions = TenantSubscription::query()
            ->with(['tenant', 'plan'])
            ->get()
            ->filter(fn (TenantSubscription $subscription): bool => $subscription->tenant !== null
                && $subscription->tenant->slug !== 'platform-admin');

        $active = $subscriptions->filter(
            fn (TenantSubscription $subscription): bool => self::subscriptionStatus($subscription->status) === 'active'
        );

        $mrr = $active->sum(fn (TenantSubscription $subscription): float => (float) ($subscription->plan?->price ?? 0));

        $plans = SubscriptionPlan::query()->orderBy('id')->get();
        $byPlan = $plans->map(function (SubscriptionPlan $plan) use ($active): array {
            $onPlan = $active->where('subscription_plan_id', $plan->id);

            return [
                'code' => (string) $plan->slug,
                'name' => (string) $plan->name,
                'venues' => $onPlan->count(),
                'mrr' => Contract::money($onPlan->count() * (float) $plan->price),
            ];
        })->filter(fn (array $plan): bool => $plan['venues'] > 0)->values();

        $invoices = SubscriptionInvoice::query()->get();
        $payments = Payment::query()->whereIn('status', ['verified', 'paid'])->get();
        $refunds = Refund::query()->where('status', 'completed')->get();

        $thisMonth = CarbonImmutable::now(self::TZ)->startOfMonth();
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = $thisMonth->subMonths($i);
            $key = $month->format('Y-m');

            $subscriptionsPaid = $invoices
                ->filter(fn (SubscriptionInvoice $invoice): bool => $invoice->status === 'paid'
                    && self::inMonth($invoice->paid_at, $key))
                ->sum(fn (SubscriptionInvoice $invoice): float => (float) $invoice->amount);

            $bookingsPaid = $payments
                ->filter(fn (Payment $payment): bool => self::inMonth($payment->created_at, $key))
                ->sum(fn (Payment $payment): float => (float) $payment->amount)
                - $refunds
                    ->filter(fn (Refund $refund): bool => self::inMonth($refund->created_at, $key))
                    ->sum(fn (Refund $refund): float => (float) $refund->amount);

            $months[] = [
                'month' => $key,
                'label' => $month->format('F Y'),
                'subscriptions_paid' => Contract::money($subscriptionsPaid),
                'bookings_paid' => Contract::money($bookingsPaid),
            ];
        }

        $unpaid = $invoices->filter(
            fn (SubscriptionInvoice $invoice): bool => in_array(self::invoiceStatus($invoice->status), ['issued', 'overdue'], true)
        );

        return [
            'mrr' => Contract::money($mrr),
            'arr' => Contract::money($mrr * 12),
            'paying_venues' => $active->count(),
            'trialing_venues' => $subscriptions->filter(
                fn (TenantSubscription $subscription): bool => self::subscriptionStatus($subscription->status) === 'trialing'
            )->count(),
            'by_plan' => $byPlan->all(),
            'by_month' => $months,
            'unpaid' => Contract::money($unpaid->sum(fn (SubscriptionInvoice $invoice): float => (float) $invoice->amount)),
            'overdue' => Contract::money(
                $unpaid->filter(fn (SubscriptionInvoice $invoice): bool => self::invoiceStatus($invoice->status) === 'overdue')
                    ->sum(fn (SubscriptionInvoice $invoice): float => (float) $invoice->amount)
            ),
        ];
    }

    /**
     * @return array{id: string, name: string, email: string}|null
     */
    private static function owner(Tenant $tenant): ?array
    {
        $tenant->loadMissing(['tenantUsers.role', 'tenantUsers.user']);

        $row = $tenant->tenantUsers->first(
            fn ($membership): bool => $membership->status === 'active'
                && $membership->role?->name === 'owner'
        );
        $user = $row?->user;

        return $user === null ? null : [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
        ];
    }

    private static function inMonth(mixed $date, string $month): bool
    {
        if ($date === null) {
            return false;
        }

        return CarbonImmutable::parse($date)->setTimezone(self::TZ)->format('Y-m') === $month;
    }
}
