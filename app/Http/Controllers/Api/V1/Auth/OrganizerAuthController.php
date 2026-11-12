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

class OrganizerAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AuthenticationService $authService,
        protected GoogleAuthenticationService $googleAuthService,
        protected AccountProvisioningService $provisioningService,
        protected TokenManagementService $tokenService
    ) {}

    /**
     * Organizer / Club Manager email & password login.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tenantId = $request->input('tenant_id');

        $user = $this->authService->attempt(
            email: $validated['email'],
            password: $validated['password'],
            role: 'club_manager',
            tenantId: $tenantId
        );

        $authResponse = $this->authService->buildAuthResponse(
            user: $user,
            activeRole: 'club_manager',
            tenantId: $tenantId,
            message: 'Organizer authentication successful.'
        );

        return $this->successResponse(
            data: $authResponse,
            message: 'Organizer authentication successful.'
        );
    }

    /**
     * Organizer Google authentication (Allowed ONLY for assigned organizers).
     */
    public function google(GoogleAuthTokenRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->googleAuthService->authenticate(
            idToken: $validated['id_token'],
            role: 'club_manager',
            extraData: $validated
        );

        $authResponse = $this->authService->buildAuthResponse(
            user: $user,
            activeRole: 'club_manager',
            tenantId: $validated['tenant_id'] ?? null,
            message: 'Organizer Google authentication successful.'
        );

        return $this->successResponse(
            data: $authResponse,
            message: 'Organizer Google authentication successful.'
        );
    }

    /**
     * Accept a Club Organizer assignment.
     */
    public function acceptAssignment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $membership = $this->provisioningService->acceptOrganizerAssignment(
            token: $validated['token'],
            user: $request->user()
        );

        return $this->successResponse(
            data: $membership->load('club'),
            message: 'Organizer assignment accepted successfully.'
        );
    }

    /**
     * Get authenticated Organizer profile.
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
                    'avatar' => $user->avatar,
                    'available_roles' => $user->getAvailableRoles(),
                    'active_role' => 'club_manager',
                ],
                'managed_clubs' => $user->clubMemberships()->with('club')->whereIn('role', ['manager', 'organizer'])->get(),
            ],
            message: 'Organizer profile retrieved.'
        );
    }

    /**
     * Organizer logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->tokenService->revokeCurrentToken($request->user());

        return $this->successResponse(message: 'Organizer logged out successfully.');
    }

    /**
     * Revoke all organizer sessions across all devices.
     */
    public function revokeAll(Request $request): JsonResponse
    {
        $this->tokenService->revokeAllTokens($request->user());

        return $this->successResponse(message: 'All device sessions have been revoked successfully.');
    }

    /**
     * Change password for authenticated organizer.
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
     * Organizer forgot password.
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
     * Organizer reset password.
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
