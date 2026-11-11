<?php

namespace App\Http\Controllers\Api\V1\Owners;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Role;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class StaffController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext
    ) {}

    /**
     * List all staff members for this tenant facility.
     */
    public function index(): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();

        $staffMembers = TenantUser::with(['user', 'role'])
            ->where('tenant_id', $tenantId)
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'owner']))
            ->get();

        return $this->successResponse(
            data: $staffMembers,
            message: 'Facility staff list retrieved.'
        );
    }

    /**
     * Add/invite a user as facility staff.
     */
    public function store(StoreStaffRequest $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();
        $validated = $request->validated();

        $user = User::where('email', $validated['email'])->firstOrFail();
        $roleName = $validated['role'] ?? 'staff';
        $role = Role::where('name', $roleName)->firstOrFail();

        $tenantUser = TenantUser::updateOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $user->id],
            ['role_id' => $role->id, 'status' => 'active']
        );

        return $this->successResponse(
            data: $tenantUser->load(['user', 'role']),
            message: "User {$user->name} assigned as {$role->label}.",
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Remove staff member from tenant.
     */
    public function destroy(User $user): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();

        TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->delete();

        return $this->successResponse(
            message: 'Staff member removed from facility.'
        );
    }
}
