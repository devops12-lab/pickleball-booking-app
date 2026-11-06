<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSubscriptionPlanRequest;
use App\Http\Resources\SubscriptionPlanResource;
use App\Http\Traits\ApiResponse;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionController extends Controller
{
    use ApiResponse;

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
}
