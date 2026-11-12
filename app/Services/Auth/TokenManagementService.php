<?php

namespace App\Services\Auth;

use App\Models\User;

/**
 * Manages Sanctum personal access token lifecycle.
 *
 * Responsible for creating, revoking, and managing API tokens.
 * Token abilities are scoped per role so a stolen token cannot escalate
 * beyond the portal it was issued for.
 */
class TokenManagementService
{
    /**
     * Abilities granted to each role portal.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_ABILITIES = [
        'system_admin' => ['*'],
        'owner' => ['owner:*', 'staff:*', 'bookings:*', 'payments:*', 'courts:*', 'reports:*'],
        'staff' => ['staff:*', 'bookings:*', 'payments:verify', 'courts:read'],
        'club_manager' => ['clubs:*', 'tournaments:*', 'matches:*'],
        'player' => ['player:*', 'bookings:create', 'bookings:read', 'clubs:join'],
    ];

    /**
     * Create an API access token scoped to a role's abilities.
     *
     * @param  User  $user  The user to issue the token for.
     * @param  string  $name  A label for the token (e.g. "player_auth_token").
     * @param  list<string>  $abilities  Explicit abilities override. When empty, derived from the token name's role prefix.
     * @return string The plain-text token (only available at creation time).
     */
    public function createToken(User $user, string $name = 'auth_token', array $abilities = ['*']): string
    {
        return $user->createToken($name, $abilities)->plainTextToken;
    }

    /**
     * Create a role-scoped token using the predefined ability map.
     */
    public function createRoleToken(User $user, string $role): string
    {
        $abilities = self::ROLE_ABILITIES[$role] ?? ['*'];

        return $this->createToken($user, "{$role}_auth_token", $abilities);
    }

    /**
     * Revoke user's current access token.
     */
    public function revokeCurrentToken(User $user): bool
    {
        return (bool) $user->currentAccessToken()?->delete();
    }

    /**
     * Revoke all access tokens across all devices.
     */
    public function revokeAllTokens(User $user): int
    {
        return $user->tokens()->delete();
    }
}
