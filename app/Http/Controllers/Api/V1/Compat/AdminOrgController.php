<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesPlatformAdmin;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Club;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Support\Compat\AdminView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Super Admin's review desk. Venues are tenants; clubs are community
 * groups. Approve/decline/suspend/reactivate follow the SPA's allowed
 * transitions, a decline or suspension needs a reason, and every decision is
 * written to the audit log.
 */
class AdminOrgController extends Controller
{
    use ApiResponse, AuthorizesPlatformAdmin;

    private const ALLOWED_FROM = [
        'approve' => ['pending_review', 'rejected'],
        'reject' => ['pending_review'],
        'suspend' => ['active'],
        'reactivate' => ['suspended'],
    ];

    private const STATUS_AFTER = [
        'approve' => 'active',
        'reject' => 'rejected',
        'suspend' => 'suspended',
        'reactivate' => 'active',
    ];

    private const VERB = [
        'approve' => 'Approved',
        'reject' => 'Declined',
        'suspend' => 'Suspended',
        'reactivate' => 'Reactivated',
    ];

    public function venues(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $status = trim((string) $request->query('status', ''));

        $rows = Tenant::query()
            ->with(['venueProfile', 'subscription.plan', 'subscriptionPlan', 'courts', 'tenantUsers.role', 'tenantUsers.user'])
            ->where('slug', '!=', 'platform-admin')
            ->get()
            ->map(fn (Tenant $tenant): array => AdminView::venueRow($tenant))
            ->filter(function (array $row) use ($q, $status): bool {
                if ($status !== '' && $row['status'] !== $status) {
                    return false;
                }

                if ($q === '') {
                    return true;
                }

                $owner = $row['owner'];

                return collect([
                    $row['name'],
                    $row['city'],
                    $row['province'],
                    $owner['name'] ?? '',
                    $owner['email'] ?? '',
                ])->contains(fn (string $value): bool => str_contains(mb_strtolower($value), $q));
            })
            ->values()
            ->all();

        usort($rows, fn (array $a, array $b): int => (
            (int) ($b['status'] === 'pending_review') - (int) ($a['status'] === 'pending_review')
        ) ?: strcasecmp($a['name'], $b['name']));

        return $this->successResponse(data: $rows);
    }

    public function venue(Tenant $venue): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        if ($venue->slug === 'platform-admin') {
            return $this->errorResponse("This venue doesn't exist.", code: Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(data: AdminView::venueDetail($venue));
    }

    public function decideVenue(Request $request, Tenant $venue): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $venue->loadMissing('venueProfile');

        if ($venue->venueProfile === null) {
            return $this->errorResponse(
                'This venue has no profile to review.',
                code: Response::HTTP_CONFLICT,
            );
        }

        return $this->decide($request, 'venue', $venue);
    }

    public function clubs(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $status = trim((string) $request->query('status', ''));

        $rows = Club::query()
            ->with(['creator', 'members', 'homeVenue'])
            ->get()
            ->map(fn (Club $club): array => AdminView::clubRow($club))
            ->filter(function (array $row) use ($q, $status): bool {
                if ($status !== '' && $row['status'] !== $status) {
                    return false;
                }

                if ($q === '') {
                    return true;
                }

                $creator = $row['created_by'];

                return collect([
                    $row['name'],
                    $row['city'],
                    $row['province'],
                    $creator['name'] ?? '',
                    $creator['email'] ?? '',
                ])->contains(fn (string $value): bool => str_contains(mb_strtolower($value), $q));
            })
            ->values()
            ->all();

        usort($rows, fn (array $a, array $b): int => (
            (int) ($b['status'] === 'pending_review') - (int) ($a['status'] === 'pending_review')
        ) ?: strcasecmp($a['name'], $b['name']));

        return $this->successResponse(data: $rows);
    }

    public function club(Club $club): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        return $this->successResponse(data: AdminView::clubDetail($club));
    }

    public function decideClub(Request $request, Club $club): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        return $this->decide($request, 'club', $club);
    }

    private function decide(Request $request, string $kind, Tenant|Club $org): JsonResponse
    {
        $noun = $kind;
        $action = (string) $request->input('action', '');
        $reason = trim((string) $request->input('reason', ''));

        if (! array_key_exists($action, self::ALLOWED_FROM)) {
            return $this->validationError([
                'action' => ['Choose approve, decline, suspend or reactivate.'],
            ]);
        }

        if (in_array($action, ['reject', 'suspend'], true) && mb_strlen($reason) < 10) {
            return $this->validationError([
                'reason' => ["Say why in a sentence (at least 10 characters). The {$noun}'s owner sees it."],
            ]);
        }

        $current = $org instanceof Tenant
            ? AdminView::venueStatus($org)
            : AdminView::clubStatus($org);

        if (! in_array($current, self::ALLOWED_FROM[$action], true)) {
            return $this->errorResponse(
                message: "This {$noun} is ".AdminView::statusWord($current).", so it can't be ".strtolower(self::VERB[$action]).".",
                code: Response::HTTP_CONFLICT,
            );
        }

        $next = self::STATUS_AFTER[$action];
        $note = in_array($action, ['reject', 'suspend'], true) ? $reason : null;

        DB::transaction(function () use ($kind, $org, $action, $next, $note): void {
            if ($org instanceof Tenant) {
                $org->venueProfile->update([
                    'status' => $next,
                    'status_reason' => $note,
                ]);

                $org->update(['status' => $next === 'active' ? 'active' : 'suspended']);

                if ($next === 'active') {
                    $this->startTrial($org);
                }
            } else {
                $org->update(['status' => $next]);
            }

            AdminView::writeAudit(
                action: "{$kind}.{$action}",
                summary: self::VERB[$action].' '.$org->name,
                type: $kind,
                id: (int) $org->id,
                name: (string) $org->name,
                reason: $note,
                tenantId: $org instanceof Tenant ? (int) $org->id : null,
            );
        });

        $fresh = $org->fresh();

        return $this->successResponse(
            data: $fresh instanceof Tenant
                ? AdminView::venueRow($fresh->load(['venueProfile', 'subscription.plan', 'subscriptionPlan', 'courts', 'tenantUsers.role', 'tenantUsers.user']))
                : AdminView::clubRow($fresh->load(['creator', 'members', 'homeVenue'])),
            message: self::VERB[$action].' '.$org->name.'.',
        );
    }

    /**
     * Approving a venue starts (or restarts) its free trial, so a venue that
     * waited in review still gets the full trial length.
     */
    private function startTrial(Tenant $tenant): void
    {
        $days = (int) PlatformSetting::get('trial_days', 14);
        $subscription = $tenant->subscription()->first();
        $planId = $subscription?->subscription_plan_id
            ?? SubscriptionPlan::query()->where('slug', 'starter')->value('id');

        if ($planId === null) {
            return;
        }

        if ($subscription === null) {
            TenantSubscription::create([
                'tenant_id' => $tenant->id,
                'subscription_plan_id' => $planId,
                'status' => 'trialing',
                'starts_at' => now(),
                'trial_ends_at' => now()->addDays($days),
            ]);
        } else {
            $subscription->update([
                'status' => 'trialing',
                'starts_at' => now(),
                'trial_ends_at' => now()->addDays($days),
            ]);
        }

        $tenant->update(['subscription_plan_id' => $planId]);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validationError(array $errors): JsonResponse
    {
        return $this->errorResponse(
            message: 'The given data was invalid.',
            errors: $errors,
            code: Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
