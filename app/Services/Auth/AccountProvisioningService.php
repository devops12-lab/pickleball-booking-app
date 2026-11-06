<?php

namespace App\Services\Auth;

use App\Models\ClubInvitation;
use App\Models\ClubMember;
use App\Models\PlayerStatistic;
use App\Models\Role;
use App\Models\StaffInvitation;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\UserAuthProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AccountProvisioningService
{
    /**
     * Atomically provision an Owner account, Tenant facility, and Owner membership.
     *
     * @return array{user: User, tenant: Tenant}
     */
    public function provisionOwner(array $userData, array $tenantData, ?array $googleDetails = null): array
    {
        return DB::transaction(function () use ($userData, $tenantData, $googleDetails) {
            $user = User::where('email', $userData['email'])->first();

            if (! $user) {
                $user = User::create([
                    'name' => $userData['name'],
                    'email' => $userData['email'],
                    'phone' => $userData['phone'] ?? null,
                    'password' => isset($userData['password']) ? Hash::make($userData['password']) : Hash::make(Str::random(32)),
                    'avatar' => $userData['avatar'] ?? null,
                    'account_status' => 'active',
                    'email_verified_at' => $googleDetails ? now() : null,
                ]);

                PlayerStatistic::create([
                    'user_id' => $user->id,
                    'current_rating' => 1200.00,
                ]);
            }

            if ($googleDetails) {
                UserAuthProvider::firstOrCreate(
                    ['provider' => 'google', 'provider_user_id' => $googleDetails['id']],
                    [
                        'user_id' => $user->id,
                        'provider_email' => $googleDetails['email'],
                        'avatar' => $googleDetails['avatar'] ?? null,
                    ]
                );
            }

            // Provision tenant facility
            $slug = Str::slug($tenantData['name']);
            if (Tenant::where('slug', $slug)->exists()) {
                $slug .= '-'.Str::lower(Str::random(6));
            }

            $tenant = Tenant::create([
                'name' => $tenantData['name'],
                'slug' => $slug,
                'business_email' => $tenantData['business_email'] ?? $user->email,
                'business_phone' => $tenantData['business_phone'] ?? $user->phone,
                'address' => $tenantData['address'] ?? null,
                'status' => 'active',
                'subscription_plan_id' => $tenantData['subscription_plan_id'] ?? null,
            ]);

            $ownerRole = Role::where('name', 'owner')->firstOrFail();

            TenantUser::updateOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['role_id' => $ownerRole->id, 'status' => 'active']
            );

            return ['user' => $user, 'tenant' => $tenant];
        });
    }

    /**
     * Atomically provision a Player global account.
     */
    public function provisionPlayer(array $userData, ?array $googleDetails = null): User
    {
        return DB::transaction(function () use ($userData, $googleDetails) {
            $user = User::where('email', $userData['email'])->first();

            if (! $user) {
                $user = User::create([
                    'name' => $userData['name'],
                    'email' => $userData['email'],
                    'phone' => $userData['phone'] ?? null,
                    'password' => isset($userData['password']) ? Hash::make($userData['password']) : Hash::make(Str::random(32)),
                    'avatar' => $userData['avatar'] ?? null,
                    'account_status' => 'active',
                    'email_verified_at' => $googleDetails ? now() : null,
                ]);

                PlayerStatistic::create([
                    'user_id' => $user->id,
                    'current_rating' => 1200.00,
                ]);
            }

            if ($googleDetails) {
                UserAuthProvider::firstOrCreate(
                    ['provider' => 'google', 'provider_user_id' => $googleDetails['id']],
                    [
                        'user_id' => $user->id,
                        'provider_email' => $googleDetails['email'],
                        'avatar' => $googleDetails['avatar'] ?? null,
                    ]
                );
            }

            return $user;
        });
    }

    /**
     * Create an invitation for a Staff member.
     */
    public function createStaffInvitation(Tenant $tenant, string $email, User $inviter, ?string $roleName = 'staff'): StaffInvitation
    {
        $role = Role::where('name', $roleName ?? 'staff')->firstOrFail();

        return StaffInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => strtolower(trim($email)),
            'role_id' => $role->id,
            'invited_by' => $inviter->id,
            'token' => Str::random(64),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);
    }

    /**
     * Accept a Staff invitation and provision/link the staff account.
     *
     * @return array{user: User, invitation: StaffInvitation}
     */
    public function acceptStaffInvitation(string $token, array $userData, ?array $googleDetails = null): array
    {
        return DB::transaction(function () use ($token, $userData, $googleDetails) {
            $invitation = StaffInvitation::where('token', $token)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->first();

            if (! $invitation) {
                throw new InvalidArgumentException('Invalid or expired staff invitation token.');
            }

            $user = User::where('email', $invitation->email)->first();

            if (! $user) {
                $user = User::create([
                    'name' => $userData['name'] ?? $invitation->email,
                    'email' => $invitation->email,
                    'password' => isset($userData['password']) ? Hash::make($userData['password']) : Hash::make(Str::random(32)),
                    'account_status' => 'active',
                    'email_verified_at' => now(),
                ]);

                PlayerStatistic::create([
                    'user_id' => $user->id,
                    'current_rating' => 1200.00,
                ]);
            }

            if ($googleDetails) {
                UserAuthProvider::firstOrCreate(
                    ['provider' => 'google', 'provider_user_id' => $googleDetails['id']],
                    [
                        'user_id' => $user->id,
                        'provider_email' => $googleDetails['email'],
                        'avatar' => $googleDetails['avatar'] ?? null,
                    ]
                );
            }

            TenantUser::updateOrCreate(
                ['tenant_id' => $invitation->tenant_id, 'user_id' => $user->id],
                ['role_id' => $invitation->role_id, 'status' => 'active']
            );

            $invitation->update(['status' => 'accepted']);

            return ['user' => $user, 'invitation' => $invitation];
        });
    }

    /**
     * Accept an Organizer / Club Manager assignment.
     */
    public function acceptOrganizerAssignment(string $token, User $user): ClubMember
    {
        return DB::transaction(function () use ($token, $user) {
            $invitation = ClubInvitation::where('token', $token)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->first();

            if (! $invitation) {
                throw new InvalidArgumentException('Invalid or expired organizer invitation token.');
            }

            $membership = ClubMember::updateOrCreate(
                ['club_id' => $invitation->club_id, 'user_id' => $user->id],
                ['role' => 'organizer', 'status' => 'active', 'joined_at' => now()]
            );

            $invitation->update(['status' => 'accepted', 'invited_user_id' => $user->id]);

            return $membership;
        });
    }
}
