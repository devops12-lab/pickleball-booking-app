<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\GoogleAuthTokenRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Auth\AccountProvisioningService;
use App\Services\Auth\AuthenticationService;
use App\Services\Auth\GoogleAuthenticationService;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\TokenManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AuthenticationService $authService,
        protected GoogleAuthenticationService $googleAuthService,
        protected AccountProvisioningService $provisioningService,
        protected TokenManagementService $tokenService
    ) {}

    /**
     * Staff email & password login.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tenantId = $request->input('tenant_id');

        $user = $this->authService->attempt(
            email: $validated['email'],
            password: $validated['password'],
            role: 'staff',
            tenantId: $tenantId
        );

        $authResponse = $this->authService->buildAuthResponse(
            user: $user,
            activeRole: 'staff',
            tenantId: $tenantId,
            message: 'Staff authentication successful.'
        );

        return $this->successResponse(
            data: $authResponse,
            message: 'Staff authentication successful.'
        );
    }

    /**
     * Staff Google authentication (Allowed ONLY for pre-invited / existing staff).
     */
    public function google(GoogleAuthTokenRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->googleAuthService->authenticate(
            idToken: $validated['id_token'],
            role: 'staff',
            extraData: $validated
        );

        $authResponse = $this->authService->buildAuthResponse(
            user: $user,
            activeRole: 'staff',
            tenantId: $validated['tenant_id'] ?? null,
            message: 'Staff Google authentication successful.'
        );

        return $this->successResponse(
            data: $authResponse,
            message: 'Staff Google authentication successful.'
        );
    }

    /**
     * Get authenticated Staff profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $assignments = $user->tenantUsers()
            ->with(['tenant', 'role'])
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'owner']))
            ->where('status', 'active')
            ->get();

        return $this->successResponse(
            data: [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'available_roles' => $user->getAvailableRoles(),
                    'active_role' => 'staff',
                ],
                'facility_assignments' => $assignments,
            ],
            message: 'Staff profile retrieved.'
        );
    }

    /**
     * Staff logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->tokenService->revokeCurrentToken($request->user());

        return $this->successResponse(message: 'Staff logged out successfully.');
    }

    /**
     * Revoke all staff sessions across all devices.
     */
    public function revokeAll(Request $request): JsonResponse
    {
        $this->tokenService->revokeAllTokens($request->user());

        return $this->successResponse(message: 'All device sessions have been revoked successfully.');
    }

    /**
     * Change password for authenticated staff.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $request->user()->update([
            'password' => Hash::make($request->validated('password')),
        ]);

        return $this->successResponse(message: 'Password changed successfully.');
    }

    /**
     * Staff forgot password.
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
     * Staff reset password.
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
}
