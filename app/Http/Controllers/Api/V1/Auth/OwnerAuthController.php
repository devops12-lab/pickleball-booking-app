<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\GoogleAuthTokenRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\OwnerRegisterRequest;
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

class OwnerAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AuthenticationService $authService,
        protected GoogleAuthenticationService $googleAuthService,
        protected AccountProvisioningService $provisioningService,
        protected TokenManagementService $tokenService
    ) {}

    /**
     * Register a new facility Owner and initial facility tenant.
     */
    public function register(OwnerRegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->provisioningService->provisionOwner(
            userData: [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'phone' => $validated['business_phone'] ?? null,
            ],
            tenantData: [
                'name' => $validated['business_name'],
                'business_email' => $validated['email'],
                'business_phone' => $validated['business_phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'subscription_plan_id' => $validated['subscription_plan_id'] ?? null,
            ]
        );

        $authResponse = $this->authService->buildAuthResponse(
            user: $result['user'],
            activeRole: 'owner',
            tenantId: $result['tenant']->id,
            message: 'Facility Owner registration successful.'
        );

        return $this->successResponse(
            data: $authResponse,
            message: 'Facility Owner registration successful.',
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Authenticate facility Owner using email & password.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tenantId = $request->input('tenant_id');

        $user = $this->authService->attempt(
            email: $validated['email'],
            password: $validated['password'],
            role: 'owner',
            tenantId: $tenantId
        );

        $authResponse = $this->authService->buildAuthResponse(
            user: $user,
            activeRole: 'owner',
            tenantId: $tenantId,
            message: 'Owner authentication successful.'
        );

        return $this->successResponse(
            data: $authResponse,
            message: 'Owner authentication successful.'
        );
    }

    /**
     * Authenticate or register facility Owner using Google OAuth.
     */
    public function google(GoogleAuthTokenRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->googleAuthService->authenticate(
            idToken: $validated['id_token'],
            role: 'owner',
            extraData: $validated
        );

        $tenantId = $validated['tenant_id'] ?? null;
        $authResponse = $this->authService->buildAuthResponse(
            user: $user,
            activeRole: 'owner',
            tenantId: $tenantId,
            message: 'Owner Google authentication successful.'
        );

        return $this->successResponse(
            data: $authResponse,
            message: 'Owner Google authentication successful.'
        );
    }

    /**
     * Get authenticated Owner profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenants = $user->tenants()->wherePivot('status', 'active')->get();

        return $this->successResponse(
            data: [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'available_roles' => $user->getAvailableRoles(),
                    'active_role' => 'owner',
                ],
                'managed_facilities' => $tenants,
            ],
            message: 'Owner profile retrieved.'
        );
    }

    /**
     * Owner logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->tokenService->revokeCurrentToken($request->user());

        return $this->successResponse(message: 'Owner logged out successfully.');
    }

    /**
     * Revoke all owner sessions across all devices.
     */
    public function revokeAll(Request $request): JsonResponse
    {
        $this->tokenService->revokeAllTokens($request->user());

        return $this->successResponse(message: 'All device sessions have been revoked successfully.');
    }

    /**
     * Change password for authenticated owner.
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
     * Owner forgot password.
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
     * Owner reset password.
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
