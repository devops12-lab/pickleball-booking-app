<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Handles password reset token generation and password updates.
 *
 * Tokens are stored hashed in the password_reset_tokens table and verified
 * with Hash::check(). All active sessions (tokens) are revoked on reset.
 *
 * @security The sendResetToken() method does not currently dispatch email.
 */
class PasswordResetService
{
    /** Reset tokens expire after this many minutes. */
    private const TOKEN_EXPIRATION_MINUTES = 60;

    /**
     * Generate and record password reset token for the given email.
     *
     * @return array{reset_token_sent: bool}
     */
    public function sendResetToken(string $email): array
    {
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        // In production, send via Mail / Notification service
        return ['reset_token_sent' => true];
    }

    /**
     * Reset user password using the provided token.
     */
    public function resetPassword(string $email, string $token, string $newPassword): bool
    {
        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('created_at', '>=', now()->subMinutes(self::TOKEN_EXPIRATION_MINUTES))
            ->first();

        if (! $record || ! Hash::check($token, $record->token)) {
            return false;
        }

        $user = User::where('email', $email)->first();
        if ($user) {
            $user->update(['password' => Hash::make($newPassword)]);
            $user->tokens()->delete(); // Revoke all active sessions on password change
        }

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return true;
    }
}
