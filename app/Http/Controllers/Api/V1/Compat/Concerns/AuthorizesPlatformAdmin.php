<?php

namespace App\Http\Controllers\Api\V1\Compat\Concerns;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The SPA's Super Admin is the backend's `system_admin` role. Everyone else
 * gets the same refusal, without a hint of what is behind the door.
 */
trait AuthorizesPlatformAdmin
{
    protected function denyUnlessPlatformAdmin(): ?JsonResponse
    {
        $user = request()->user();

        if ($user === null) {
            return $this->errorResponse('Unauthenticated.', code: Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->isSystemAdmin()) {
            return $this->errorResponse(
                'This area is for the Super Admin.',
                code: Response::HTTP_FORBIDDEN,
            );
        }

        return null;
    }
}
