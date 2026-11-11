<?php

namespace App\Http\Middleware;

use App\Http\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    use ApiResponse;

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            return $this->errorResponse(
                message: 'Your account has been suspended or is not active. Please contact support.',
                code: Response::HTTP_FORBIDDEN
            );
        }

        return $next($request);
    }
}
