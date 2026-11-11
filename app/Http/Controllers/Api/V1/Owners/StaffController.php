<?php

namespace App\Http\Controllers\Api\V1\Owners;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Http\Traits\ApiResponse;
use App\Models\Role;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class StaffController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext,
        protected AuditLogService $auditLog
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
            ->orderBy('created_at')
            ->get();

        return $this->successResponse(
            data: StaffResource::collection($staffMembers),
            message: 'Facility staff list retrieved.'
        );
    }

    /**
     * Create a staff account with full credentials under the owner's tenant.
     *
     * No email invitation is involved: the owner provides the staff member's
     * name, email, password and position directly.
     */
    public function store(StoreStaffRequest $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();
        $validated = $request->validated();

        $role = Role::where('name', 'staff')->firstOrFail();

        $staffUser = DB::transaction(function () use ($validated, $tenantId, $role): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'account_status' => 'active',
                // The owner vouches for the account; no invitation email is sent.
                'email_verified_at' => now(),
            ]);

            TenantUser::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'position' => $validated['position'],
                'status' => 'active',
            ]);

            return $user;
        });

        $tenantUser = TenantUser::query()
            ->with(['user', 'role'])
            ->where('tenant_id', $tenantId)
            ->where('user_id', $staffUser->id)
            ->firstOrFail();

        $this->auditLog->log('staff_created', $tenantUser, $tenantId, $request->user(), null, [
            'staff_user_id' => $staffUser->id,
            'email' => $staffUser->email,
            'position' => $validated['position'],
        ]);

        return $this->successResponse(
            data: new StaffResource($tenantUser),
            message: "Staff account for {$staffUser->name} created successfully.",
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Update a staff member's position or membership status.
     */
    public function update(UpdateStaffRequest $request, User $user): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();

        $tenantUser = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->whereHas('role', fn ($q) => $q->where('name', 'staff'))
            ->firstOrFail();

        $old = ['position' => $tenantUser->position?->value, 'status' => $tenantUser->status];

        $tenantUser->fill($request->validatedPayload())->save();

        $this->auditLog->log('staff_updated', $tenantUser->refresh()->load(['user', 'role']), $tenantId, $request->user(), $old, [
            'position' => $tenantUser->position?->value,
            'status' => $tenantUser->status,
        ]);

        return $this->successResponse(
            data: new StaffResource($tenantUser->load(['user', 'role'])),
            message: 'Staff member updated successfully.'
        );
    }

    /**
     * Remove staff member from tenant.
     */
    public function destroy(User $user): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();

        $removed = TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->whereHas('role', fn ($q) => $q->where('name', 'staff'))
            ->first();

        if ($removed === null) {
            return $this->errorResponse('Staff member not found in this facility.', code: Response::HTTP_NOT_FOUND);
        }

        TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->whereHas('role', fn ($q) => $q->where('name', 'staff'))
            ->delete();

        $this->auditLog->log('staff_removed', null, $tenantId, auth()->user(), ['staff_user_id' => $user->id], null);

        return $this->successResponse(
            message: 'Staff member removed from facility.'
        );
    }
}
