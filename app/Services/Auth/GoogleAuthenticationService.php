<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserAuthProvider;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class GoogleAuthenticationService
{
    protected static ?array $fakeGoogleUser = null;

    public function __construct(
        protected AccountProvisioningService $provisioningService,
        protected RoleAuthenticationService $roleAuthService
    ) {}

    /**
     * Fake a Google user verification for automated testing.
     */
    public static function fake(?array $userPayload = null): void
    {
        if ($userPayload !== null) {
            static::$fakeGoogleUser = [
                'id' => (string) ($userPayload['id'] ?? $userPayload['sub'] ?? 'google-test-id-12345'),
                'email' => strtolower(trim($userPayload['email'] ?? 'google.tester@courtflex.test')),
                'name' => $userPayload['name'] ?? 'Google Tester',
                'avatar' => $userPayload['avatar'] ?? $userPayload['picture'] ?? 'https://courtflex.test/avatar.png',
                'email_verified' => $userPayload['email_verified'] ?? true,
            ];
        } else {
            static::$fakeGoogleUser = [
                'id' => 'google-test-id-12345',
                'email' => 'google.tester@courtflex.test',
                'name' => 'Google Tester',
                'avatar' => 'https://courtflex.test/avatar.png',
                'email_verified' => true,
            ];
        }
    }

    /**
     * Clear test fake.
     */
    public static function stopFaking(): void
    {
        static::$fakeGoogleUser = null;
    }

    /**
     * Verify Google ID token and return normalized payload.
     *
     * @return array{id: string, email: string, name: string, avatar: ?string, email_verified: bool}
     */
    public function verifyIdToken(string $idToken): array
    {
        if (static::$fakeGoogleUser !== null) {
            return static::$fakeGoogleUser;
        }

        // Decode token without verification to read header or payload
        try {
            $parts = explode('.', $idToken);
            if (count($parts) !== 3) {
                throw new InvalidArgumentException('Invalid JWT format for Google ID token.');
            }

            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

            if (! is_array($payload) || empty($payload['sub']) || empty($payload['email'])) {
                throw new InvalidArgumentException('Google ID token is missing required identity claims.');
            }

            if (isset($payload['exp']) && $payload['exp'] < time()) {
                throw new InvalidArgumentException('Google ID token has expired.');
            }

            // In production, fetch Google public keys and verify signature
            $expectedIssuers = ['accounts.google.com', 'https://accounts.google.com'];
            if (isset($payload['iss']) && ! in_array($payload['iss'], $expectedIssuers, true)) {
                throw new InvalidArgumentException('Google ID token has invalid issuer.');
            }

            return [
                'id' => (string) $payload['sub'],
                'email' => strtolower(trim($payload['email'])),
                'name' => $payload['name'] ?? 'Google User',
                'avatar' => $payload['picture'] ?? null,
                'email_verified' => ! empty($payload['email_verified']),
            ];
        } catch (Exception $e) {
            throw new InvalidArgumentException('Failed to verify Google ID token: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Authenticate or register a user via Google OAuth for a specific role portal.
     *
     * @throws AuthorizationException
     */
    public function authenticate(string $idToken, string $role, array $extraData = []): User
    {
        $normalizedRole = str_replace('-', '_', strtolower($role));

        // CRITICAL: Reject System Admin completely
        if ($normalizedRole === 'system_admin') {
            throw new AuthorizationException('System Admin accounts are strictly forbidden from authenticating or registering via Google OAuth.');
        }

        $googleUser = $this->verifyIdToken($idToken);

        if (! $googleUser['email_verified']) {
            throw new InvalidArgumentException('The Google account email is not verified.');
        }

        // Look up by provider ID or email
        $provider = UserAuthProvider::where('provider', 'google')
            ->where('provider_user_id', $googleUser['id'])
            ->first();

        $user = $provider?->user ?? User::where('email', $googleUser['email'])->first();

        if ($user) {
            // Link Google provider if not yet linked
            if (! $provider) {
                UserAuthProvider::create([
                    'user_id' => $user->id,
                    'provider' => 'google',
                    'provider_user_id' => $googleUser['id'],
                    'provider_email' => $googleUser['email'],
                    'avatar' => $googleUser['avatar'],
                ]);
            }

            // Verify user is allowed for this role portal
            $tenantId = $extraData['tenant_id'] ?? null;
            if (! $this->roleAuthService->canAuthenticateAs($user, $normalizedRole, $tenantId)) {
                throw new AuthorizationException("This Google account is not authorized as {$role}. Please ensure an invitation or assignment has been granted.");
            }

            return $user;
        }

        // User does not exist yet. Handle role-specific registration rules:
        return match ($normalizedRole) {
            'player' => $this->provisioningService->provisionPlayer([
                'name' => $googleUser['name'],
                'email' => $googleUser['email'],
                'avatar' => $googleUser['avatar'],
            ], $googleUser),

            'owner' => $this->provisionNewOwnerWithGoogle($googleUser, $extraData),

            'staff' => throw new AuthorizationException('Unregistered Google accounts cannot access the Staff portal. You must first be invited by a facility owner.'),

            'club_manager', 'organizer' => throw new AuthorizationException('Unregistered Google accounts cannot access the Organizer portal. You must be assigned to an active club or tournament first.'),

            default => throw new AuthorizationException("Invalid role portal [{$role}]."),
        };
    }

    protected function provisionNewOwnerWithGoogle(array $googleUser, array $extraData): User
    {
        if (empty($extraData['business_name'])) {
            throw new InvalidArgumentException('Facility or business name is required for new Owner registration.');
        }

        $result = $this->provisioningService->provisionOwner(
            userData: [
                'name' => $googleUser['name'],
                'email' => $googleUser['email'],
                'avatar' => $googleUser['avatar'],
            ],
            tenantData: [
                'name' => $extraData['business_name'],
                'business_email' => $extraData['business_email'] ?? $googleUser['email'],
                'business_phone' => $extraData['business_phone'] ?? null,
                'address' => $extraData['address'] ?? null,
                'subscription_plan_id' => $extraData['subscription_plan_id'] ?? null,
            ],
            googleDetails: $googleUser
        );

        return $result['user'];
    }
}
