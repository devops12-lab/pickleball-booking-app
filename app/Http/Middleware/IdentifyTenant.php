<?php

namespace App\Http\Middleware;

use App\Http\Traits\ApiResponse;
use App\Models\Tenant;
use App\Services\TenantContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantIdentifier = $request->header('X-Tenant-ID')
            ?? $request->route('tenant')
            ?? $request->input('tenant_id');

        if ($tenantIdentifier) {
            $tenant = is_numeric($tenantIdentifier)
                ? Tenant::find($tenantIdentifier)
                : Tenant::where('slug', $tenantIdentifier)->first();

            if (! $tenant) {
                return $this->errorResponse(
                    message: 'Requested tenant facility was not found.',
                    code: Response::HTTP_NOT_FOUND
                );
            }

            if (! $tenant->isActive()) {
                return $this->errorResponse(
                    message: 'The requested facility account is inactive or suspended.',
                    code: Response::HTTP_FORBIDDEN
                );
            }

            $this->tenantContext->setTenant($tenant);
        }

        return $next($request);
    }
}
