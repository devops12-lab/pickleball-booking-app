<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesClub;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/clubs/{club}/staff — a venue's people are its tenant users. The SPA's
 * five role keys are mapped onto this backend's role names.
 */
class StaffController extends Controller
{
    use ApiResponse, AuthorizesClub;

    /** SPA role key => backend role names, in order of preference. */
    private const ROLE_CANDIDATES = [
        'owner' => ['owner'],
        'manager' => ['club_manager', 'manager'],
        'front_desk' => ['staff', 'front_desk'],
        'court_attendant' => ['staff', 'court_attendant'],
        'scorer' => ['staff', 'scorer'],
    ];

    public function index(Tenant $club): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        $members = $club->tenantUsers()->with(['user', 'role'])->orderBy('id')->get();

        return $this->successResponse(
            data: $members->map(fn (TenantUser $member) => $this->shape($member))->all()
        );
    }

    public function store(Request $request, Tenant $club): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'role_key' => ['required', 'in:manager,front_desk,court_attendant,scorer'],
        ]);

        $roleId = $this->roleIdFor($data['role_key']);

        if (! $roleId) {
            return $this->errorResponse(
                "No backend role matches {$data['role_key']}.",
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $user = User::firstOrCreate(
            ['email' => $data['email']],
            [
                'name' => $data['name'],
                'password' => Str::random(40),
                'account_status' => 'active',
            ]
        );

        $exists = TenantUser::where('tenant_id', $club->id)->where('user_id', $user->id)->first();

        if ($exists && $exists->status === 'active') {
            return $this->errorResponse(
                'That person is already on this club\'s staff.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $member = TenantUser::updateOrCreate(
            ['tenant_id' => $club->id, 'user_id' => $user->id],
            ['role_id' => $roleId, 'status' => 'invited']
        );

        return $this->successResponse(
            data: $this->shape($member->load(['user', 'role'])),
            message: 'Staff invited.',
            code: Response::HTTP_CREATED
        );
    }

    public function update(Request $request, Tenant $club, TenantUser $membership): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $membership->tenant_id)) {
            return $this->errorResponse('Staff member not found in this club.', code: Response::HTTP_NOT_FOUND);
        }

        if ($membership->user_id === $request->user()->id) {
            return $this->errorResponse('You cannot change your own role.', code: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $request->validate([
            'role_key' => ['required', 'in:manager,front_desk,court_attendant,scorer'],
        ]);

        $roleId = $this->roleIdFor($data['role_key']);

        if (! $roleId) {
            return $this->errorResponse(
                "No backend role matches {$data['role_key']}.",
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $membership->update(['role_id' => $roleId]);

        return $this->successResponse(
            data: $this->shape($membership->load(['user', 'role'])),
            message: 'Staff role updated.'
        );
    }

    public function destroy(Request $request, Tenant $club, TenantUser $membership): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $membership->tenant_id)) {
            return $this->errorResponse('Staff member not found in this club.', code: Response::HTTP_NOT_FOUND);
        }

        if ($membership->user_id === $request->user()->id) {
            return $this->errorResponse('You cannot remove yourself.', code: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $membership->delete();

        return $this->successResponse(message: 'Staff removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(TenantUser $member): array
    {
        return [
            'membership_id' => (string) $member->id,
            'user' => [
                'id' => (string) $member->user_id,
                'name' => (string) ($member->user?->name ?? ''),
                'email' => (string) ($member->user?->email ?? ''),
            ],
            'role_key' => $this->roleKey($member->role?->name),
            'status' => $member->status === 'invited' ? 'invited' : 'active',
            'invited_by' => null,
            'joined_at' => $member->created_at?->toIso8601String(),
            'is_you' => request()->user()?->id === $member->user_id,
        ];
    }

    private function roleKey(?string $name): string
    {
        $normalized = strtolower(trim((string) $name));

        foreach (self::ROLE_CANDIDATES as $key => $candidates) {
            if (in_array($normalized, $candidates, true)) {
                return $key;
            }
        }

        return 'manager';
    }

    private function roleIdFor(string $roleKey): ?int
    {
        foreach (self::ROLE_CANDIDATES[$roleKey] ?? [] as $name) {
            $role = Role::where('name', $name)->first();

            if ($role) {
                return (int) $role->id;
            }
        }

        return null;
    }
}
