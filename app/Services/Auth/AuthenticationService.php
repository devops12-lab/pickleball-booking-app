<?php

namespace App\Services\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;

class AuthenticationService
{
    public function __construct(
        protected RoleAuthenticationService $roleAuthService,
        protected TokenManagementService $tokenService,
        protected AuditLogService $auditLogService
    ) {}

    /**
     * Authenticate user credentials against a specific role portal.
     *
     * @throws AuthenticationException|AuthorizationException
     */
    public function attempt(string $email, string $password, string $role, ?int $tenantId = null): User
    {
        $user = User::where('email', strtolower(trim($email)))->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->auditLogService->log(
                action: 'auth.failed',
                user: $user,
                oldValues: ['email' => $email, 'attempted_role' => $role]
            );

            throw new AuthenticationException('Invalid email or password provided.');
        }

        if (! $user->isActive()) {
            throw new AuthorizationException('Your account is currently inactive or suspended. Please contact support.');
        }

        if (! $this->roleAuthService->canAuthenticateAs($user, $role, $tenantId)) {
            $this->auditLogService->log(
                action: 'auth.role_denied',
                user: $user,
                oldValues: ['attempted_role' => $role]
            );

            throw new AuthorizationException("Access denied: Your account is not authorized as [{$role}].");
        }

        $this->auditLogService->log(
            action: 'auth.success',
            user: $user,
            newValues: ['role' => $role]
        );

        return $user;
    }

    /**
     * Build the standardized CourtFlex authentication response envelope.
     */
    public function buildAuthResponse(User $user, string $activeRole, ?int $tenantId = null, string $message = 'Authentication successful.'): array
    {
        $token = $this->tokenService->createToken($user, "{$activeRole}_auth_token");

        // Determine active context if tenantId provided or found on user's memberships
        $context = null;
        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                $context = [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'tenant_slug' => $tenant->slug,
                ];
            }
        } elseif (in_array($activeRole, ['owner', 'staff'])) {
            $firstTenantUser = $user->tenantUsers()->with('tenant')->where('status', 'active')->first();
            if ($firstTenantUser?->tenant) {
                $context = [
                    'tenant_id' => $firstTenantUser->tenant->id,
                    'tenant_name' => $firstTenantUser->tenant->name,
                    'tenant_slug' => $firstTenantUser->tenant->slug,
                ];
            }
        }

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'account_status' => $user->account_status,
                'available_roles' => $user->getAvailableRoles(),
                'active_role' => $activeRole,
            ],
            'access_token' => $token,
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => config('sanctum.expiration') ? ((int) config('sanctum.expiration') * 60) : null,
            'permissions' => $this->getEffectivePermissions($user, $activeRole, $tenantId),
            'context' => $context,
        ];
    }

    /**
     * Get effective permission slugs for the user in the active role.
     */
    protected function getEffectivePermissions(User $user, string $activeRole, ?int $tenantId = null): array
    {
        if ($user->isSystemAdmin()) {
            return ['*'];
        }

        return $user->tenantUsers()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->with('role.permissions')
            ->get()
            ->flatMap(fn ($tu) => $tu->role?->permissions ?? collect())
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }
}
