<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use App\Models\UserSuspension;
use App\Support\Compat\MePayload;
use App\Support\Compat\PlatformView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session-based auth shaped like the contract the CourtFlex SPA already speaks
 * (`Me` in packages/api/src/types.ts). The role-scoped controllers under
 * Auth/ hand out bearer tokens; this one relies on Sanctum's SPA cookies so the
 * frontend never has to store a token in JavaScript.
 */
class AuthController extends Controller
{
    use ApiResponse;

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt($validated)) {
            return $this->errorResponse(
                message: 'These credentials do not match our records.',
                errors: ['email' => ['These credentials do not match our records.']],
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /** @var User $user */
        $user = $request->user();

        if (! $user->isActive()) {
            $suspension = UserSuspension::query()
                ->where('user_id', $user->id)
                ->whereNull('lifted_at')
                ->latest('id')
                ->first();

            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            $message = 'This account is suspended.';

            if ($suspension !== null) {
                $message .= ' Reason: '.$suspension->reason;
            }

            $message .= ' '.PlatformView::supportLine();

            return $this->errorResponse(
                message: $message,
                code: Response::HTTP_FORBIDDEN,
            );
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return $this->successResponse(
            data: MePayload::for($user),
            message: 'Signed in.'
        );
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->successResponse(message: 'Signed out.');
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->successResponse(data: MePayload::for($user));
    }
}
