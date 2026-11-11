<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminLoginRequest;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Auth\AuthenticationService;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\TokenManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AdminAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AuthenticationService $authService,
        protected TokenManagementService $tokenService
    ) {}

    /**
     * System Admin authentication (Email & password only. No Google allowed).
     */
    public function login(AdminLoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->authService->attempt(
            email: $validated['email'],
            password: $validated['password'],
            role: 'system_admin'
        );

        if ($user->mfa_enabled && empty($validated['mfa_code'])) {
            return $this->errorResponse(
                message: 'MFA challenge required. Please provide a valid 6-digit MFA code.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $response = $this->authService->buildAuthResponse(
            user: $user,
            activeRole: 'system_admin',
            message: 'System Admin authentication successful.'
        );

        return $this->successResponse(
            data: $response,
            message: 'System Admin authentication successful.'
        );
    }

    /**
     * Get authenticated System Admin profile.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->successResponse(
            data: [
                'user' => [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'avatar' => $request->user()->avatar,
                    'mfa_enabled' => $request->user()->mfa_enabled,
                    'is_system_admin' => true,
                    'active_role' => 'system_admin',
                    'available_roles' => $request->user()->getAvailableRoles(),
                ],
                'permissions' => ['*'],
            ],
            message: 'Admin profile retrieved.'
        );
    }

    /**
     * Admin logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->tokenService->revokeCurrentToken($request->user());

        return $this->successResponse(message: 'System Admin logged out successfully.');
    }

    /**
     * Revoke all admin sessions across all devices.
     */
    public function revokeAll(Request $request): JsonResponse
    {
        $this->tokenService->revokeAllTokens($request->user());

        return $this->successResponse(message: 'All device sessions have been revoked successfully.');
    }

    /**
     * Change password for authenticated system admin.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $request->user()->update([
            'password' => Hash::make($request->validated('password')),
        ]);

        return $this->successResponse(message: 'Password changed successfully.');
    }

    /**
     * Admin forgot password.
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
     * Admin reset password.
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
