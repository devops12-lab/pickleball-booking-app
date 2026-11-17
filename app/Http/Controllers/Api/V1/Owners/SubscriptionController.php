<?php

namespace App\Http\Controllers\Api\V1\Owners;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriptions\CancelSubscriptionRequest;
use App\Http\Requests\Subscriptions\ChangeIntervalRequest;
use App\Http\Requests\Subscriptions\DowngradePlanRequest;
use App\Http\Requests\Subscriptions\SubscribeRequest;
use App\Http\Requests\Subscriptions\UpgradePlanRequest;
use App\Http\Traits\ApiResponse;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\SubscriptionEntitlementService;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext,
        protected SubscriptionEntitlementService $entitlements
    ) {}

    /**
     * Current subscription details + usage summary.
     */
    public function show(): JsonResponse
    {
        $tenant = $this->tenant();
        $subscription = $tenant->subscription()->with(['plan', 'pendingPlan'])->first();

        return $this->successResponse(
            data: [
                'subscription' => $subscription,
                'usage' => $this->entitlements->usage($tenant),
            ],
            message: 'Subscription retrieved.'
        );
    }

    /**
     * Subscribe to a plan (creates an issued invoice for manual payment).
     */
    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        $tenant = $this->tenant();

        $existing = $tenant->subscription()
            ->whereIn('status', ['active', 'trialing'])
            ->first();

        if ($existing) {
            return $this->errorResponse(
                'You already have an active subscription. Use upgrade or downgrade instead.',
                code: Response::HTTP_CONFLICT
            );
        }

        $plan = SubscriptionPlan::where('id', $request->validated('plan_id'))
            ->where('is_active', true)
            ->first();

        if (! $plan) {
            return $this->errorResponse('Selected plan is not available.', code: Response::HTTP_NOT_FOUND);
        }

        $interval = $request->validated('billing_interval');

        if ($interval === 'yearly' && $plan->yearly_price === null) {
            return $this->errorResponse('Yearly billing is not available for this plan.', code: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $amount = $interval === 'yearly' ? $plan->yearly_price : $plan->price;

        $subscription = TenantSubscription::create([
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => $plan->id,
            'billing_interval' => $interval,
            'status' => 'pending',
            'starts_at' => now(),
            'plan_snapshot' => [
                'name' => $plan->name,
                'price' => (float) $plan->price,
                'yearly_price' => $plan->yearly_price !== null ? (float) $plan->yearly_price : null,
                'max_courts' => $plan->max_courts,
                'max_staff' => $plan->max_staff,
                'features' => $plan->features,
            ],
        ]);

        $invoice = SubscriptionInvoice::create([
            'tenant_id' => $tenant->id,
            'tenant_subscription_id' => $subscription->id,
            'invoice_number' => 'INV-'.strtoupper(Str::random(10)),
            'amount' => $amount,
            'status' => 'pending',
            'due_at' => now()->addDays(7),
        ]);

        $tenant->update(['subscription_plan_id' => $plan->id]);

        return $this->successResponse(
            data: [
                'subscription' => $subscription->load('plan'),
                'invoice' => $invoice,
            ],
            message: 'Subscription created. Please complete payment to activate.',
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Change billing interval (monthly ↔ yearly), applied at next renewal.
     */
    public function changeBillingInterval(ChangeIntervalRequest $request): JsonResponse
    {
        $subscription = $this->activeSubscription();

        if (! $subscription) {
            return $this->noSubscriptionError();
        }

        $newInterval = $request->validated('billing_interval');

        if ($subscription->billing_interval === $newInterval) {
            return $this->errorResponse('Already on this billing interval.', code: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($newInterval === 'yearly' && $subscription->plan?->yearly_price === null) {
            return $this->errorResponse('Yearly billing is not available for this plan.', code: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $subscription->update(['pending_billing_interval' => $newInterval]);

        return $this->successResponse(
            data: $subscription->fresh(),
            message: "Billing interval will change to {$newInterval} at next renewal."
        );
    }

    /**
     * Immediate upgrade to a higher plan.
     */
    public function upgrade(UpgradePlanRequest $request): JsonResponse
    {
        $subscription = $this->activeSubscription();

        if (! $subscription) {
            return $this->noSubscriptionError();
        }

        $newPlan = SubscriptionPlan::where('id', $request->validated('plan_id'))
            ->where('is_active', true)
            ->first();

        if (! $newPlan) {
            return $this->errorResponse('Selected plan is not available.', code: Response::HTTP_NOT_FOUND);
        }

        if ($newPlan->price <= $subscription->plan->price) {
            return $this->errorResponse('Upgrade requires a higher-priced plan. Use downgrade for lower plans.', code: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $subscription->update([
            'subscription_plan_id' => $newPlan->id,
            'plan_snapshot' => [
                'name' => $newPlan->name,
                'price' => (float) $newPlan->price,
                'yearly_price' => $newPlan->yearly_price !== null ? (float) $newPlan->yearly_price : null,
                'max_courts' => $newPlan->max_courts,
                'max_staff' => $newPlan->max_staff,
                'features' => $newPlan->features,
            ],
        ]);

        $this->tenant()->update(['subscription_plan_id' => $newPlan->id]);

        return $this->successResponse(
            data: $subscription->fresh()->load('plan'),
            message: 'Plan upgraded. New limits apply immediately.'
        );
    }

    /**
     * Request downgrade to a lower plan (applied at end of current period).
     */
    public function downgrade(DowngradePlanRequest $request): JsonResponse
    {
        $subscription = $this->activeSubscription();

        if (! $subscription) {
            return $this->noSubscriptionError();
        }

        $newPlan = SubscriptionPlan::where('id', $request->validated('plan_id'))
            ->where('is_active', true)
            ->first();

        if (! $newPlan) {
            return $this->errorResponse('Selected plan is not available.', code: Response::HTTP_NOT_FOUND);
        }

        if ($newPlan->price >= $subscription->plan->price) {
            return $this->errorResponse('Downgrade requires a lower-priced plan. Use upgrade for higher plans.', code: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $tenant = $this->tenant();
        $courtCount = $tenant->courts()->count();
        $staffCount = $tenant->tenantUsers()
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'owner']))
            ->count();

        if ($newPlan->max_courts !== null && $courtCount > $newPlan->max_courts) {
            return $this->errorResponse(
                "Cannot downgrade: you have {$courtCount} courts but the new plan allows {$newPlan->max_courts}. Remove courts first.",
                code: Response::HTTP_CONFLICT
            );
        }

        if ($newPlan->max_staff !== null && $staffCount > $newPlan->max_staff) {
            return $this->errorResponse(
                "Cannot downgrade: you have {$staffCount} staff but the new plan allows {$newPlan->max_staff}. Remove staff first.",
                code: Response::HTTP_CONFLICT
            );
        }

        $subscription->update(['pending_plan_id' => $newPlan->id]);

        return $this->successResponse(
            data: $subscription->fresh()->load(['plan', 'pendingPlan']),
            message: 'Downgrade scheduled for end of current billing period.'
        );
    }

    /**
     * Cancel subscription (access continues until period end).
     */
    public function cancel(CancelSubscriptionRequest $request): JsonResponse
    {
        $subscription = $this->activeSubscription();

        if (! $subscription) {
            return $this->noSubscriptionError();
        }

        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $request->validated('reason'),
        ]);

        return $this->successResponse(
            data: $subscription->fresh(),
            message: 'Subscription cancelled. Access continues until end of current period.'
        );
    }

    /**
     * Paginated invoice history.
     */
    public function invoices(Request $request): JsonResponse
    {
        $invoices = SubscriptionInvoice::where('tenant_id', $this->tenantContext->getTenantId())
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return $this->successResponse(
            data: $invoices,
            meta: [
                'current_page' => $invoices->currentPage(),
                'total' => $invoices->total(),
            ]
        );
    }

    /**
     * Resource usage statistics.
     */
    public function usage(): JsonResponse
    {
        return $this->successResponse(
            data: $this->entitlements->usage($this->tenant()),
            message: 'Usage retrieved.'
        );
    }

    private function tenant(): Tenant
    {
        return Tenant::findOrFail($this->tenantContext->getTenantId());
    }

    private function activeSubscription(): ?TenantSubscription
    {
        return $this->tenant()->subscription()
            ->with('plan')
            ->whereIn('status', ['active', 'trialing', 'cancelled'])
            ->first();
    }

    private function noSubscriptionError(): JsonResponse
    {
        return $this->errorResponse('No active subscription found.', code: Response::HTTP_NOT_FOUND);
    }
}
