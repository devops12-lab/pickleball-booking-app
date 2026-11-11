<?php

namespace App\Http\Middleware;

use App\Http\Traits\ApiResponse;
use App\Models\UserSuspension;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspension ends the account's session immediately. The SPA treats 401 as
 * "signed out", so an open session gets the same answer as a fresh sign-in.
 */
class EnsureCompatAccountActive
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isActive()) {
            $suspension = UserSuspension::query()
                ->where('user_id', $user->id)
                ->whereNull('lifted_at')
                ->latest('id')
                ->first();

            $message = 'Your session has ended. This account is suspended.';

            if ($suspension !== null) {
                $message .= ' Reason: '.$suspension->reason;
            }

            return $this->errorResponse($message, code: Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
