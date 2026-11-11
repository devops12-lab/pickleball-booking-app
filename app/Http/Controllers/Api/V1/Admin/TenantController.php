<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTenantRequest;
use App\Http\Requests\Admin\UpdateTenantStatusRequest;
use App\Http\Resources\TenantResource;
use App\Http\Traits\ApiResponse;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TenantController extends Controller
{
    use ApiResponse;

    /**
     * List all tenants across the platform.
     */
    public function index(Request $request): JsonResponse
    {
        $tenants = Tenant::when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->paginate($request->integer('per_page', 20));

        return $this->successResponse(
            data: TenantResource::collection($tenants),
            meta: [
                'current_page' => $tenants->currentPage(),
                'total' => $tenants->total(),
            ]
        );
    }

    /**
     * Create a new tenant from system admin.
     */
    public function store(StoreTenantRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $slug = Str::slug($validated['name']);
        if (Tenant::where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(6));
        }

        $tenant = Tenant::create(array_merge($validated, [
            'slug' => $slug,
            'status' => $validated['status'] ?? 'active',
        ]));

        return $this->successResponse(
            data: new TenantResource($tenant),
            message: 'Tenant facility business created by admin.',
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Show tenant details.
     */
    public function show(Tenant $tenant): JsonResponse
    {
        return $this->successResponse(
            data: new TenantResource($tenant->load(['facilities', 'subscription'])),
            message: 'Tenant details retrieved.'
        );
    }

    /**
     * Update tenant status (active, suspended, trial).
     */
    public function updateStatus(UpdateTenantStatusRequest $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validated();

        $tenant->update(['status' => $validated['status']]);

        return $this->successResponse(
            data: new TenantResource($tenant),
            message: "Tenant status updated to {$tenant->status}."
        );
    }
}
