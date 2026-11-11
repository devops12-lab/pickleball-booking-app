<?php

namespace App\Http\Middleware;

use App\Http\Traits\ApiResponse;
use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    use ApiResponse;

    public function __construct(
        protected PermissionService $permissionService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->errorResponse(
                message: 'Unauthenticated.',
                code: Response::HTTP_UNAUTHORIZED
            );
        }

        if (! $this->permissionService->can($user, $permission)) {
            return $this->errorResponse(
                message: "You lack the required permission [{$permission}] for this action.",
                code: Response::HTTP_FORBIDDEN
            );
        }

        return $next($request);
    }
}
