<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesPlatformAdmin;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use App\Models\UserSuspension;
use App\Services\Mail\EmailSettingsService;
use App\Support\Compat\AdminView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every account on CourtFlex: search by name, email or mobile, inspect what
 * someone is across the system, suspend/reactivate with a reason, and send a
 * password reset link. A suspension ends open sessions and blocks sign-in.
 */
class AdminUserController extends Controller
{
    use ApiResponse, AuthorizesPlatformAdmin;

    public function __construct(private readonly EmailSettingsService $mail) {}

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $role = trim((string) $request->query('role', ''));
        $status = trim((string) $request->query('status', ''));
        $digits = $this->phoneDigits($q);

        $rows = User::query()
            ->with([
                'tenantUsers.role', 'tenantUsers.tenant', 'clubMemberships.club',
                'playerProfile', 'suspensions',
            ])
            ->get()
            ->map(fn (User $user): array => AdminView::userRow($user))
            ->filter(function (array $row) use ($q, $role, $status, $digits): bool {
                if ($role !== '' && ! in_array($role, $row['roles'], true)) {
                    return false;
                }

                if ($status !== '' && $row['status'] !== $status) {
                    return false;
                }

                if ($q === '') {
                    return true;
                }

                if (str_contains(mb_strtolower($row['name']), $q) || str_contains($row['email'], $q)) {
                    return true;
                }

                return $digits !== null
                    && $row['phone'] !== null
                    && str_contains($this->phoneDigits((string) $row['phone']) ?? '', $digits);
            })
            ->values()
            ->all();

        usort($rows, fn (array $a, array $b): int => (
            (int) ($b['status'] === 'suspended') - (int) ($a['status'] === 'suspended')
        ) ?: strcasecmp($a['name'], $b['name']));

        return $this->successResponse(data: $rows);
    }

    public function show(User $user): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        return $this->successResponse(data: AdminView::userDetail($user));
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $status = (string) $request->input('status', '');
        $reason = trim((string) $request->input('reason', ''));

        if (! in_array($status, ['suspended', 'active'], true)) {
            return $this->validationError([
                'status' => ['Choose suspended or active.'],
            ]);
        }

        $user->loadMissing('suspensions');
        $active = $user->suspensions->first(fn (UserSuspension $row): bool => $row->lifted_at === null);

        if ($status === 'suspended') {
            if ((int) $user->id === (int) $request->user()->id) {
                return $this->errorResponse("You can't suspend your own account.", code: Response::HTTP_CONFLICT);
            }

            if ($user->isSystemAdmin()) {
                return $this->errorResponse("Super Admin accounts can't be suspended.", code: Response::HTTP_CONFLICT);
            }

            if ($active !== null) {
                return $this->errorResponse('This account is already suspended.', code: Response::HTTP_CONFLICT);
            }

            if (mb_strlen($reason) < 10) {
                return $this->validationError([
                    'reason' => ['Say why in a sentence (at least 10 characters). They see it when they try to sign in.'],
                ]);
            }

            DB::transaction(function () use ($user, $request, $reason): void {
                UserSuspension::create([
                    'user_id' => $user->id,
                    'suspended_by' => $request->user()->id,
                    'reason' => $reason,
                    'suspended_at' => now(),
                ]);

                $user->forceFill(['account_status' => 'suspended'])->save();

                // The suspension ends every open session and API token.
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->tokens()->delete();

                AdminView::writeAudit(
                    action: 'user.suspended',
                    summary: "Suspended {$user->name}'s account",
                    type: 'user',
                    id: (int) $user->id,
                    name: (string) $user->name,
                    reason: $reason,
                );
            });
        } else {
            if ($active === null) {
                return $this->errorResponse("This account isn't suspended.", code: Response::HTTP_CONFLICT);
            }

            DB::transaction(function () use ($user, $request, $active, $reason): void {
                $active->update([
                    'lifted_at' => now(),
                    'lifted_by' => $request->user()->id,
                    'lift_reason' => $reason !== '' ? $reason : null,
                ]);

                $user->forceFill(['account_status' => 'active'])->save();

                AdminView::writeAudit(
                    action: 'user.reactivated',
                    summary: "Reactivated {$user->name}'s account",
                    type: 'user',
                    id: (int) $user->id,
                    name: (string) $user->name,
                    reason: $reason !== '' ? $reason : null,
                );
            });
        }

        return $this->successResponse(
            data: AdminView::userRow($user->fresh(['suspensions'])),
            message: $status === 'suspended' ? 'Account suspended.' : 'Account reactivated.',
        );
    }

    public function sendPasswordReset(Request $request, User $user): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $user->loadMissing('suspensions');
        $active = $user->suspensions->first(fn (UserSuspension $row): bool => $row->lifted_at === null);

        if ($active !== null || $user->account_status === 'suspended') {
            return $this->errorResponse(
                "Reactivate the account first. Suspended accounts can't sign in.",
                code: Response::HTTP_CONFLICT,
            );
        }

        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => now()],
        );

        $this->mail->trySend(
            $user->email,
            'Reset your CourtFlex password',
            [
                'A Super Admin sent you a link to choose a new password.',
                'This link works for 30 minutes. If you did not ask for it, ignore this email.',
            ],
            $this->mail->frontendUrl("/reset-password?token={$token}"),
            'Choose a new password',
        );

        AdminView::writeAudit(
            action: 'user.password_reset_sent',
            summary: "Sent {$user->name} a password reset link",
            type: 'user',
            id: (int) $user->id,
            name: (string) $user->name,
            reason: null,
        );

        return $this->successResponse(
            data: ['sent' => true],
            message: 'Password reset link sent.',
        );
    }

    private function phoneDigits(string $value): ?string
    {
        $digits = preg_replace('/\\D/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }

        return strlen($digits) >= 4 ? $digits : null;
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
