<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\GoogleAuthTokenRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Http\Traits\ApiResponse;
use App\Services\Auth\AccountProvisioningService;
use App\Services\Auth\AuthenticationService;
use App\Services\Auth\GoogleAuthenticationService;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\TokenManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class PlayerAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AuthenticationService $authService,
        protected GoogleAuthenticationService $googleAuthService,
        protected AccountProvisioningService $provisioningService,
        protected TokenManagementService $tokenService
    ) {}

    /**
     * Register a new player account.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->provisioningService->provisionPlayer([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => $validated['password'],
        ]);

        $authResponse = $this->authService->buildAuthResponse(
            user: $user,
            activeRole: 'player',
            message: 'Player registration successful. Welcome to CourtFlex!'
        );

        return $this->successResponse(
            data: $authResponse,
            message: 'Player registration successful.',
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Authenticate player via email & password.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->authService->attempt(
            email: $validated['email'],
            password: $validated['password'],
            role: 'player'
        );

        $authResponse = $this->authService->buildAuthResponse(
            user: $user,
            activeRole: 'player',
            message: 'Player authentication successful.'
        );

        return $this->successResponse(
            data: $authResponse,
            message: 'Player authentication successful.'
        );
    }

    /**
     * Authenticate or register player via Google OAuth.
     */
    public function google(GoogleAuthTokenRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->googleAuthService->authenticate(
            idToken: $validated['id_token'],
            role: 'player'
        );

        $authResponse = $this->authService->buildAuthResponse(
            user: $user,
            activeRole: 'player',
            message: 'Player Google authentication successful.'
        );

        return $this->successResponse(
            data: $authResponse,
            message: 'Player Google authentication successful.'
        );
    }

    /**
     * Get authenticated player profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse(
            data: [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'avatar' => $user->avatar,
                    'account_status' => $user->account_status,
                    'email_verified_at' => $user->email_verified_at,
                    'available_roles' => $user->getAvailableRoles(),
                    'active_role' => 'player',
                ],
                'statistics' => $user->statistics,
            ],
            message: 'Player profile retrieved.'
        );
    }

    /**
     * Player logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->tokenService->revokeCurrentToken($request->user());

        return $this->successResponse(message: 'Player logged out successfully.');
    }

    /**
     * Revoke all player sessions across all devices.
     */
    public function revokeAll(Request $request): JsonResponse
    {
        $this->tokenService->revokeAllTokens($request->user());

        return $this->successResponse(message: 'All device sessions have been revoked successfully.');
    }

    /**
     * Change password for authenticated player.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update([
            'password' => Hash::make($request->validated('password')),
        ]);

        // Revoke all tokens except the current one so other sessions are invalidated.
        $currentToken = $user->currentAccessToken();
        if ($currentToken && method_exists($currentToken, 'getKey')) {
            $user->tokens()->where('id', '!=', $currentToken->getKey())->delete();
        } else {
            $user->tokens()->delete();
        }

        return $this->successResponse(message: 'Password changed successfully.');
    }

    /**
     * Player forgot password.
     */
    public function forgotPassword(ForgotPasswordRequest $request, PasswordResetService $resetService): JsonResponse
    {
        $resetService->sendResetToken($request->validated('email'));

        return $this->successResponse(
            data: ['reset_token_sent' => true],
            message: 'Password reset link has been dispatched to your email.'
        );
    }

    /**
     * Player reset password.
     */
    public function resetPassword(ResetPasswordRequest $request, PasswordResetService $resetService): JsonResponse
    {
        $validated = $request->validated();
        $success = $resetService->resetPassword($validated['email'], $validated['token'], $validated['password']);

        if (! $success) {
            return $this->errorResponse(
                message: 'Invalid or expired password reset token.',
                errors: ['token' => ['The password reset token is invalid.']]
            );
        }

        return $this->successResponse(
            message: 'Password has been reset successfully. Please log in with your new credentials.'
        );
    }

    /**
     * Send email verification notification.
     */
    public function sendVerificationNotification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return $this->successResponse(
                message: 'Email address is already verified.'
            );
        }

        return $this->successResponse(
            message: 'Verification notification dispatched to your email address.'
        );
    }

    /**
     * Verify email.
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user && $user->email_verified_at === null) {
            $user->update(['email_verified_at' => now()]);
        }

        return $this->successResponse(
            data: $user ? new UserResource($user->fresh()) : null,
            message: 'Email verified successfully.'
        );
    }
}
