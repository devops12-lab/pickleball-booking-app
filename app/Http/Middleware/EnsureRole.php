<?php

namespace App\Http\Middleware;

use App\Http\Traits\ApiResponse;
use App\Services\TenantContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->errorResponse(
                message: 'Unauthenticated.',
                code: Response::HTTP_UNAUTHORIZED
            );
        }

        $tenantId = $this->tenantContext->getTenantId();

        $hasRequiredRole = false;
        foreach ($roles as $role) {
            if ($user->hasRole($role, $tenantId)) {
                $hasRequiredRole = true;
                break;
            }
        }

        if (! $hasRequiredRole) {
            $roleList = implode(', ', $roles);

            return $this->errorResponse(
                message: "Unauthorized: You do not possess the required role [{$roleList}] for this API endpoint.",
                code: Response::HTTP_FORBIDDEN
            );
        }

        return $next($request);
    }
}
