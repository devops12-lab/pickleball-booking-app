<?php

namespace App\Services\Auth;

use App\Models\User;

class TokenManagementService
{
    /**
     * Create an API access token with specific abilities and role context.
     */
    public function createToken(User $user, string $name = 'auth_token', array $abilities = ['*']): string
    {
        return $user->createToken($name, $abilities)->plainTextToken;
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
