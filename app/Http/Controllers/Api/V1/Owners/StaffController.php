<?php

namespace App\Http\Controllers\Api\V1\Owners;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffCredentialsRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\SubscriptionEntitlementService;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class StaffController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext,
        protected SubscriptionEntitlementService $entitlements,
        protected AuditLogService $auditLog,
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
            data: $staffMembers->map(fn (TenantUser $m): array => $this->shape($m)),
            message: 'Facility staff list retrieved.',
        );
    }

    /**
     * Add/invite a user as facility staff.
     */
    public function store(StoreStaffRequest $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();
        $tenant = Tenant::findOrFail($tenantId);

        $this->entitlements->checkOrFail($tenant, 'staff');

        $validated = $request->validated();

        $user = User::firstOrCreate(
            ['email' => $validated['email']],
            [
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make(Str::random(40)),
                'account_status' => 'active',
            ],
        );

        $isNewUser = $user->wasRecentlyCreated;

        $role = Role::where('name', 'staff')->firstOrFail();

        $existing = TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->first();

        if ($existing && $existing->status === 'active') {
            return $this->errorResponse(
                'This user is already an active staff member of this facility.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $tenantUser = TenantUser::updateOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $user->id],
            ['role_id' => $role->id, 'status' => $isNewUser ? 'invited' : 'active'],
        );

        $this->auditLog->log(
            action: 'staff.added',
            entity: $tenantUser,
            tenantId: $tenantId,
            newValues: ['email' => $user->email, 'name' => $user->name, 'role' => 'staff', 'status' => $tenantUser->status],
        );

        return $this->successResponse(
            data: $this->shape($tenantUser->load(['user', 'role'])),
            message: "Staff member {$user->name} added.",
            code: Response::HTTP_CREATED,
        );
    }

    /**
     * View a single staff member's details.
     */
    public function show(User $user): JsonResponse
    {
        $tenantUser = $this->findTenantUser($user);

        if (! $tenantUser) {
            return $this->errorResponse('Staff member not found in this facility.', code: Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(
            data: $this->shape($tenantUser->load(['user', 'role'])),
            message: 'Staff member details retrieved.',
        );
    }

    /**
     * Update staff member information (name, phone, role).
     */
    public function update(UpdateStaffRequest $request, User $user): JsonResponse
    {
        $tenantUser = $this->findTenantUser($user);

        if (! $tenantUser) {
            return $this->errorResponse('Staff member not found in this facility.', code: Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validated();
        $changes = [];

        if (isset($validated['name']) && $validated['name'] !== $user->name) {
            $changes['name'] = ['from' => $user->name, 'to' => $validated['name']];
            $user->update(['name' => $validated['name']]);
        }

        if (array_key_exists('phone', $validated) && $validated['phone'] !== $user->phone) {
            $changes['phone'] = ['from' => $user->phone, 'to' => $validated['phone']];
            $user->update(['phone' => $validated['phone']]);
        }

        if (isset($validated['role'])) {
            $newRole = Role::where('name', $validated['role'])->firstOrFail();

            if ($newRole->id !== $tenantUser->role_id) {
                $oldRoleName = $tenantUser->role?->name;
                $tenantUser->update(['role_id' => $newRole->id]);
                $changes['role'] = ['from' => $oldRoleName, 'to' => $validated['role']];
            }
        }

        if ($changes !== []) {
            $this->auditLog->log(
                action: 'staff.updated',
                entity: $tenantUser,
                tenantId: $this->tenantContext->getTenantId(),
                newValues: $changes,
            );
        }

        return $this->successResponse(
            data: $this->shape($tenantUser->fresh()->load(['user', 'role'])),
            message: 'Staff member updated.',
        );
    }

    /**
     * Activate or deactivate a staff member.
     */
    public function updateStatus(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return $this->errorResponse(
                'You cannot change your own status.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $tenantUser = $this->findTenantUser($user);

        if (! $tenantUser) {
            return $this->errorResponse('Staff member not found in this facility.', code: Response::HTTP_NOT_FOUND);
        }

        $request->validate([
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        $oldStatus = $tenantUser->status;
        $newStatus = $request->input('status');

        $tenantUser->update(['status' => $newStatus]);

        if ($newStatus === 'inactive') {
            $user->tokens()->delete();
        }

        $this->auditLog->log(
            action: 'staff.status_changed',
            entity: $tenantUser,
            tenantId: $this->tenantContext->getTenantId(),
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => $newStatus],
        );

        return $this->successResponse(
            data: $this->shape($tenantUser->fresh()->load(['user', 'role'])),
            message: "Staff member {$newStatus}.",
        );
    }

    /**
     * Update staff member credentials (email, password).
     */
    public function updateCredentials(UpdateStaffCredentialsRequest $request, User $user): JsonResponse
    {
        $tenantUser = $this->findTenantUser($user);

        if (! $tenantUser) {
            return $this->errorResponse('Staff member not found in this facility.', code: Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validated();
        $changes = [];

        if (isset($validated['email']) && $validated['email'] !== $user->email) {
            $changes['email'] = ['from' => $user->email, 'to' => $validated['email']];
            $user->update(['email' => $validated['email']]);
        }

        if (isset($validated['password'])) {
            $user->update(['password' => Hash::make($validated['password'])]);
            $changes['password'] = 'changed';
        }

        if ($changes !== []) {
            $user->tokens()->delete();

            $this->auditLog->log(
                action: 'staff.credentials_changed',
                entity: $tenantUser,
                tenantId: $this->tenantContext->getTenantId(),
                newValues: array_map(
                    fn ($v) => is_array($v) ? $v : $v,
                    $changes,
                ),
            );
        }

        return $this->successResponse(
            data: $this->shape($tenantUser->fresh()->load(['user', 'role'])),
            message: $changes !== [] ? 'Staff credentials updated.' : 'No changes detected.',
        );
    }

    /**
     * Remove staff member from tenant.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return $this->errorResponse(
                'You cannot remove yourself.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $tenantUser = $this->findTenantUser($user);

        if (! $tenantUser) {
            return $this->errorResponse('Staff member not found in this facility.', code: Response::HTTP_NOT_FOUND);
        }

        $this->auditLog->log(
            action: 'staff.removed',
            entity: $tenantUser,
            tenantId: $this->tenantContext->getTenantId(),
            oldValues: ['email' => $user->email, 'name' => $user->name, 'role' => $tenantUser->role?->name],
        );

        $tenantUser->delete();

        return $this->successResponse(message: 'Staff member removed from facility.');
    }

    private function findTenantUser(User $user): ?TenantUser
    {
        return TenantUser::with(['user', 'role'])
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->where('user_id', $user->id)
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'owner']))
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(TenantUser $member): array
    {
        return [
            'id' => $member->id,
            'user' => [
                'id' => $member->user_id,
                'name' => $member->user?->name,
                'email' => $member->user?->email,
                'phone' => $member->user?->phone,
            ],
            'role' => $member->role?->name,
            'status' => $member->status,
            'created_at' => $member->created_at?->toIso8601String(),
        ];
    }
}
