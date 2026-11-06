<?php

namespace App\Http\Middleware;

use App\Http\Traits\ApiResponse;
use App\Services\PermissionService;
use App\Services\TenantContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext,
        protected PermissionService $permissionService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->errorResponse(
                message: 'Unauthenticated.',
                code: Response::HTTP_UNAUTHORIZED
            );
        }

        $tenant = $this->tenantContext->getTenant();

        if (! $tenant) {
            return $this->errorResponse(
                message: 'No tenant context provided for this operation.',
                code: Response::HTTP_BAD_REQUEST
            );
        }

        if (! $this->permissionService->hasTenantAccess($user, $tenant->id)) {
            return $this->errorResponse(
                message: 'You do not have administrative or staff access to this facility tenant.',
                code: Response::HTTP_FORBIDDEN
            );
        }

        return $next($request);
    }
}
