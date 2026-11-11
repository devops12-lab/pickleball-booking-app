<?php

namespace App\Http\Controllers\Api\V1\Compat\Concerns;

use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A compat "club" is a tenant. Only its own active members (or the platform
 * super admin) may read or change it; every other call is refused before the
 * controller does any work.
 */
trait AuthorizesClub
{
    protected function denyUnlessMember(Tenant $club): ?JsonResponse
    {
        $user = request()->user();

        if (! $user) {
            return $this->errorResponse('Unauthenticated.', code: Response::HTTP_UNAUTHORIZED);
        }

        if (in_array('super_admin', $user->getAvailableRoles(), true)) {
            return null;
        }

        // A suspension or decline stops changes, but the owner can still read
        // the club page to see the status and the reason.
        if (! request()->isMethod('GET') && ! request()->isMethod('HEAD')) {
            $club->loadMissing('venueProfile');
            $status = $club->venueProfile?->status;

            if ($status === 'suspended' || $status === 'rejected') {
                $message = $status === 'suspended'
                    ? "This venue is suspended by CourtFlex, so it can't be changed."
                    : "CourtFlex declined this venue, so it can't be changed.";

                return $this->errorResponse(
                    $message.' Contact support.',
                    code: Response::HTTP_FORBIDDEN
                );
            }
        }

        $isMember = TenantUser::query()
            ->where('tenant_id', $club->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            return $this->errorResponse(
                'You do not have access to this club.',
                code: Response::HTTP_FORBIDDEN
            );
        }

        return null;
    }

    protected function belongsToClub(Tenant $club, ?int $tenantId): bool
    {
        return $tenantId !== null && (int) $tenantId === (int) $club->id;
    }
}
