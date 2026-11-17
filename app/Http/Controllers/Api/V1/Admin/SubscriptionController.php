<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSubscriptionPlanRequest;
use App\Http\Requests\Admin\UpdateSubscriptionPlanRequest;
use App\Http\Resources\SubscriptionPlanResource;
use App\Http\Traits\ApiResponse;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AuditLogService $auditLog
    ) {}

    /**
     * List subscription plans and active tenant subscriptions.
     */
    public function index(): JsonResponse
    {
        $plans = SubscriptionPlan::withCount('subscriptions')->get();
        $recentSubscriptions = TenantSubscription::with(['tenant', 'plan'])->latest()->take(20)->get();

        return $this->successResponse(
            data: [
                'plans' => SubscriptionPlanResource::collection($plans),
                'recent_subscriptions' => $recentSubscriptions,
            ],
            message: 'SaaS subscriptions retrieved.'
        );
    }

    /**
     * Create a new subscription plan.
     */
    public function storePlan(StoreSubscriptionPlanRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $slug = Str::slug($validated['name']);
        if (SubscriptionPlan::where('slug', $slug)->exists()) {
            $slug .= '-'.Str::random(4);
        }

        $plan = SubscriptionPlan::create(array_merge($validated, [
            'slug' => $slug,
            'is_active' => true,
        ]));

        return $this->successResponse(
            data: new SubscriptionPlanResource($plan),
            message: 'Subscription plan created.',
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Show a single subscription plan by slug.
     */
    public function showPlan(string $code): JsonResponse
    {
        $plan = SubscriptionPlan::where('slug', $code)
            ->withCount('subscriptions')
            ->first();

        if (! $plan) {
            return $this->errorResponse('Plan not found.', code: Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(
            data: new SubscriptionPlanResource($plan),
            message: 'Plan retrieved.'
        );
    }

    /**
     * Update a subscription plan.
     */
    public function updatePlan(UpdateSubscriptionPlanRequest $request, string $code): JsonResponse
    {
        $plan = SubscriptionPlan::where('slug', $code)->first();

        if (! $plan) {
            return $this->errorResponse('Plan not found.', code: Response::HTTP_NOT_FOUND);
        }

        $old = $plan->only(['name', 'price', 'yearly_price', 'max_courts', 'max_staff', 'is_active']);

        $plan->update($request->validated());

        $this->auditLog->log('subscription_plan_updated', $plan, null, $request->user(), $old, $plan->fresh()->only(['name', 'price', 'yearly_price', 'max_courts', 'max_staff', 'is_active']));

        return $this->successResponse(
            data: new SubscriptionPlanResource($plan->fresh()),
            message: 'Plan updated.'
        );
    }

    /**
     * Disable (soft-delete) a subscription plan.
     * Blocked if active subscribers exist.
     */
    public function destroyPlan(string $code): JsonResponse
    {
        $plan = SubscriptionPlan::where('slug', $code)->first();

        if (! $plan) {
            return $this->errorResponse('Plan not found.', code: Response::HTTP_NOT_FOUND);
        }

        $activeCount = TenantSubscription::where('subscription_plan_id', $plan->id)
            ->whereIn('status', ['active', 'trialing'])
            ->count();

        if ($activeCount > 0) {
            return $this->errorResponse(
                "Cannot delete plan with {$activeCount} active subscriber(s). Deactivate it instead.",
                code: Response::HTTP_CONFLICT
            );
        }

        $plan->update(['is_active' => false]);

        $this->auditLog->log('subscription_plan_disabled', $plan, null, auth()->user());

        return $this->successResponse(message: 'Plan disabled.');
    }
}
