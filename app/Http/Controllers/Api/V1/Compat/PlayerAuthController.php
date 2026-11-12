<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\Auth\AccountProvisioningService;
use App\Services\Mail\EmailSettingsService;
use App\Support\Compat\MePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Player sign-up and password recovery for the SPA (§8.2 of the contract).
 *
 * Registering signs the player in through the same session as /auth/login, so
 * after the call the SPA already has a session and simply refetches /me.
 * Verification codes and reset tokens are stored hashed and delivered through
 * the Super Admin's mail settings; when mail is off they fall back to the log.
 */
class PlayerAuthController extends Controller
{
    use ApiResponse;

    private const CODE_TTL_MINUTES = 15;

    private const CODE_MAX_ATTEMPTS = 5;

    private const RESEND_COOLDOWN_SECONDS = 60;

    private const RESET_TTL_MINUTES = 30;

    private const REGISTRATION_TTL_MINUTES = 30;

    private const REGISTRATION_RESEND_SECONDS = 60;

    public function __construct(
        private readonly AccountProvisioningService $provisioning,
        private readonly EmailSettingsService $mail,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name', ''));
        $email = Str::lower(trim((string) $request->input('email', '')));
        $phone = preg_replace('/[\s-]/', '', (string) $request->input('phone', ''));
        $password = (string) $request->input('password', '');
        $confirmation = (string) $request->input('password_confirmation', '');

        $errors = [];

        if (mb_strlen($name) < 2) {
            $errors['name'][] = 'Enter your name as other players should see it.';
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'Enter a valid email address.';
        } elseif (User::query()->where('email', $email)->exists()) {
            $errors['email'][] = 'An account already uses this email. Sign in instead.';
        }

        $normalizedPhone = null;
        if ($phone !== '') {
            if (! preg_match('/^(09\d{9}|\+639\d{9})$/', $phone)) {
                $errors['phone'][] = 'Use a mobile number like 0917 123 4567.';
            } else {
                $normalizedPhone = preg_replace('/^\+63/', '0', $phone);
                if (User::query()->where('phone', $normalizedPhone)->exists()) {
                    $errors['phone'][] = 'An account already uses this number.';
                }
            }
        }

        $errors += $this->passwordErrors($password, $confirmation) ?? [];

        if (! $request->boolean('accept_terms')) {
            $errors['accept_terms'][] = 'Accept the terms to create an account.';
        }

        if ($errors !== []) {
            return $this->validationError($errors);
        }

        $user = $this->provisioning->provisionPlayer([
            'name' => $name,
            'email' => $email,
            'phone' => $normalizedPhone,
            'password' => $password,
        ]);

        $verificationRequired = $this->mail->settings()['verification_required'];

        if ($verificationRequired) {
            $this->issueCode($user);
        } else {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return $this->successResponse(
            data: MePayload::for($user->fresh()),
            message: $verificationRequired
                ? 'Account created. Check your email for the six-digit code.'
                : 'Account created.',
            code: Response::HTTP_CREATED,
        );
    }

    /**
     * `POST /auth/register/check-email` — lets the chat ask before the password
     * step whether an email already has an account.
     */
    public function checkEmail(Request $request): JsonResponse
    {
        $email = Str::lower(trim((string) $request->input('email', '')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->validationError(['email' => ['Enter a valid email address.']]);
        }

        return $this->successResponse(data: [
            'available' => ! User::query()->where('email', $email)->exists(),
        ]);
    }

    /**
     * `POST /auth/register/start` — hold the details while the email is
     * confirmed. No account exists until `complete`, and the player is never
     * signed in by this registration flow.
     */
    public function startRegistration(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name', ''));
        $email = Str::lower(trim((string) $request->input('email', '')));
        $phone = preg_replace('/[\s-]/', '', (string) $request->input('phone', ''));
        $password = (string) $request->input('password', '');
        $confirmation = (string) $request->input('password_confirmation', '');

        $errors = [];

        if (mb_strlen($name) < 2) {
            $errors['name'][] = 'Enter your name as other players should see it.';
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'Enter a valid email address.';
        } elseif (User::query()->where('email', $email)->exists()) {
            $errors['email'][] = 'An account already uses this email. Sign in instead.';
        }

        $normalizedPhone = null;
        if ($phone !== '') {
            if (! preg_match('/^(09\d{9}|\+639\d{9})$/', $phone)) {
                $errors['phone'][] = 'Use a mobile number like 0917 123 4567.';
            } else {
                $normalizedPhone = preg_replace('/^\+63/', '0', $phone);
                if (User::query()->where('phone', $normalizedPhone)->exists()) {
                    $errors['phone'][] = 'An account already uses this number.';
                }
            }
        }

        $errors += $this->passwordErrors($password, $confirmation) ?? [];

        if ($errors !== []) {
            return $this->validationError($errors);
        }

        $now = now();
        $verificationRequired = $this->mail->settings()['verification_required'];
        $token = Str::random(64);
        $code = $verificationRequired ? (string) random_int(100000, 999999) : null;

        $pending = [
            'name' => $name,
            'email' => $email,
            'phone' => $normalizedPhone,
            'password' => Crypt::encryptString($password),
            'verification_required' => $verificationRequired,
            'verified' => ! $verificationRequired,
            'code_hash' => $code === null ? null : Hash::make($code),
            'attempts' => 0,
            'last_sent_at' => $now->getTimestamp(),
            'code_expires_at' => $now->copy()->addMinutes(self::CODE_TTL_MINUTES)->getTimestamp(),
            'expires_at' => $now->copy()->addMinutes(self::REGISTRATION_TTL_MINUTES)->getTimestamp(),
        ];

        $this->putPendingRegistration($token, $pending);

        if ($code !== null) {
            $this->mail->trySend(
                $email,
                'Your CourtFlex verification code',
                [
                    "Your verification code is {$code}.",
                    'It expires in 15 minutes.',
                ],
            );
        }

        return $this->successResponse(data: [
            'registration_token' => $token,
            'email' => $email,
            'verification_required' => $verificationRequired,
            'expires_at' => $now->copy()->addMinutes(self::REGISTRATION_TTL_MINUTES)->toIso8601String(),
            'resend_after_seconds' => self::REGISTRATION_RESEND_SECONDS,
        ], code: Response::HTTP_CREATED);
    }

    /**
     * `POST /auth/register/verify` — check the email code before creating the
     * account.
     */
    public function verifyRegistration(Request $request): JsonResponse
    {
        $token = (string) $request->input('registration_token', '');
        $pending = $this->pendingRegistration($token);

        if ($pending === null) {
            return $this->expiredRegistration();
        }

        if (! $pending['verification_required'] || $pending['verified']) {
            return $this->successResponse(data: ['verified' => true]);
        }

        if ($pending['code_expires_at'] < now()->getTimestamp()) {
            return $this->validationError([
                'code' => ['That code has expired. Send a new code.'],
            ]);
        }

        if ($pending['attempts'] >= self::CODE_MAX_ATTEMPTS) {
            return $this->errorResponse(
                message: 'Too many wrong codes. Send a new code and try again.',
                code: Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $code = preg_replace('/\s/', '', (string) $request->input('code', ''));

        if ($code === '' || ! Hash::check($code, (string) $pending['code_hash'])) {
            $pending['attempts']++;
            $this->putPendingRegistration($token, $pending);
            $left = self::CODE_MAX_ATTEMPTS - $pending['attempts'];

            if ($left <= 0) {
                return $this->errorResponse(
                    message: 'Too many wrong codes. Send a new code and try again.',
                    code: Response::HTTP_TOO_MANY_REQUESTS,
                );
            }

            return $this->validationError([
                'code' => ["That code isn't right. {$left} ".($left === 1 ? 'try' : 'tries').' left.'],
            ]);
        }

        $pending['verified'] = true;
        $this->putPendingRegistration($token, $pending);

        return $this->successResponse(data: ['verified' => true]);
    }

    /**
     * `POST /auth/register/resend` — a fresh code for the same pending sign-up.
     */
    public function resendRegistration(Request $request): JsonResponse
    {
        $token = (string) $request->input('registration_token', '');
        $pending = $this->pendingRegistration($token);

        if ($pending === null) {
            return $this->expiredRegistration();
        }

        if (! $pending['verification_required'] || $pending['verified']) {
            return $this->successResponse(data: ['resend_after_seconds' => 0]);
        }

        $wait = (int) ceil(
            self::REGISTRATION_RESEND_SECONDS - (now()->getTimestamp() - (int) $pending['last_sent_at'])
        );

        if ($wait > 0) {
            return $this->errorResponse(
                message: "You can ask for a new code in {$wait} seconds.",
                code: Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $code = (string) random_int(100000, 999999);
        $pending['code_hash'] = Hash::make($code);
        $pending['attempts'] = 0;
        $pending['last_sent_at'] = now()->getTimestamp();
        $pending['code_expires_at'] = now()->addMinutes(self::CODE_TTL_MINUTES)->getTimestamp();
        $this->putPendingRegistration($token, $pending);

        $this->mail->trySend(
            $pending['email'],
            'Your CourtFlex verification code',
            [
                "Your verification code is {$code}.",
                'It expires in 15 minutes.',
            ],
        );

        return $this->successResponse(data: [
            'resend_after_seconds' => self::REGISTRATION_RESEND_SECONDS,
        ]);
    }

    /**
     * `POST /auth/register/complete` — create the verified account without
     * signing it in. The success screen sends the player to the sign-in page.
     */
    public function completeRegistration(Request $request): JsonResponse
    {
        $token = (string) $request->input('registration_token', '');
        $pending = $this->pendingRegistration($token);

        if ($pending === null) {
            return $this->expiredRegistration();
        }

        if ($pending['expires_at'] < now()->getTimestamp()) {
            Cache::forget($this->registrationKey($token));

            return $this->expiredRegistration();
        }

        if ($pending['verification_required'] && ! $pending['verified']) {
            return $this->validationError([
                'code' => ['Confirm your email with the code we sent before creating the account.'],
            ]);
        }

        if (! $request->boolean('accept_terms')) {
            return $this->validationError([
                'accept_terms' => ['Accept the terms to create an account.'],
            ]);
        }

        if (User::query()->where('email', $pending['email'])->exists()) {
            Cache::forget($this->registrationKey($token));

            return $this->validationError([
                'email' => ['An account already uses this email. Sign in instead.'],
            ]);
        }

        try {
            $password = Crypt::decryptString((string) $pending['password']);
        } catch (\Throwable) {
            Cache::forget($this->registrationKey($token));

            return $this->expiredRegistration();
        }

        $user = $this->provisioning->provisionPlayer([
            'name' => $pending['name'],
            'email' => $pending['email'],
            'phone' => $pending['phone'],
            'password' => $password,
        ]);

        // The emailed code (or the Super Admin's verification-off setting) is
        // proof of ownership, so the account starts verified.
        $user->forceFill(['email_verified_at' => now()])->save();
        Cache::forget($this->registrationKey($token));

        return $this->successResponse(
            data: [
                'created' => true,
                'name' => $user->name,
                'email' => $user->email,
            ],
            message: 'Account created. You can now sign in.',
            code: Response::HTTP_CREATED,
        );
    }

    public function verify(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return $this->successResponse(data: MePayload::for($user));
        }

        $record = EmailVerificationCode::query()->where('user_id', $user->id)->first();

        if ($record === null) {
            return $this->validationError([
                'code' => ['Send a new code to your email, then try again.'],
            ]);
        }

        if ($record->expires_at->isPast()) {
            return $this->validationError([
                'code' => ['That code has expired. Send a new code.'],
            ]);
        }

        if ($record->attempts >= self::CODE_MAX_ATTEMPTS) {
            return $this->errorResponse(
                message: 'Too many wrong codes. Send a new code and try again.',
                code: Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $code = preg_replace('/\s/', '', (string) $request->input('code', ''));

        if ($code === '' || ! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');
            $left = self::CODE_MAX_ATTEMPTS - $record->attempts;

            if ($left <= 0) {
                return $this->errorResponse(
                    message: 'Too many wrong codes. Send a new code and try again.',
                    code: Response::HTTP_TOO_MANY_REQUESTS,
                );
            }

            return $this->validationError([
                'code' => ["That code isn't right. {$left} ".($left === 1 ? 'try' : 'tries').' left.'],
            ]);
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        $record->delete();

        return $this->successResponse(
            data: MePayload::for($user->fresh()),
            message: 'Email verified.',
        );
    }

    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = EmailVerificationCode::query()->where('user_id', $user->id)->first();

        if ($record !== null) {
            $wait = (int) ceil(self::RESEND_COOLDOWN_SECONDS - $record->last_sent_at->diffInSeconds(now()));

            if ($wait > 0) {
                return $this->errorResponse(
                    message: "You can ask for a new code in {$wait} seconds.",
                    code: Response::HTTP_TOO_MANY_REQUESTS,
                );
            }
        }

        $this->issueCode($user);

        return $this->successResponse(data: ['resend_after_seconds' => self::RESEND_COOLDOWN_SECONDS]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $email = Str::lower(trim((string) $request->input('email', '')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->validationError([
                'email' => ['Enter the email you signed up with.'],
            ]);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                ['token' => Hash::make($token), 'created_at' => now()],
            );

            $this->mail->trySend(
                $email,
                'Reset your CourtFlex password',
                [
                    'We received a request to choose a new password for your CourtFlex account.',
                    'This link works for 30 minutes. If you did not ask for it, ignore this email.',
                ],
                $this->mail->frontendUrl("/reset-password?token={$token}"),
                'Choose a new password',
            );
        }

        // Same answer either way: the form must not reveal which emails exist.
        return $this->successResponse(
            data: ['sent' => true],
            message: 'If that email has an account, a reset link is on its way.',
        );
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $token = (string) $request->input('token', '');
        $password = (string) $request->input('password', '');
        $confirmation = (string) $request->input('password_confirmation', '');

        $record = $token === ''
            ? null
            : DB::table('password_reset_tokens')
                ->where('created_at', '>=', now()->subMinutes(self::RESET_TTL_MINUTES))
                ->get()
                ->first(fn (object $row) => Hash::check($token, $row->token));

        if ($record === null) {
            return $this->errorResponse(
                message: 'This reset link has expired or was already used. Ask for a new one.',
                code: Response::HTTP_GONE,
            );
        }

        $errors = $this->passwordErrors($password, $confirmation);

        if ($errors !== null) {
            return $this->validationError($errors);
        }

        $user = User::query()->where('email', $record->email)->first();

        if ($user === null) {
            return $this->errorResponse(
                message: 'This reset link has expired or was already used. Ask for a new one.',
                code: Response::HTTP_GONE,
            );
        }

        $user->update(['password' => Hash::make($password)]);
        $user->tokens()->delete();

        // A password change ends every session, including this one. Signing out
        // here is deliberate: AuthenticateSession would otherwise reject the next
        // request with a 401 before any route runs, and the player has to sign in
        // with the new password anyway.
        if (Auth::guard('web')->id() === $user->id) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        DB::table('password_reset_tokens')->where('email', $record->email)->delete();

        return $this->successResponse(
            data: ['reset' => true],
            message: 'Password reset. Sign in with your new password.',
        );
    }

    /**
     * Pending registrations live for 30 minutes and never create an account
     * until the code is confirmed. The password is encrypted at rest in cache.
     *
     * @return array<string, mixed>|null
     */
    private function pendingRegistration(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $pending = Cache::get($this->registrationKey($token));

        return is_array($pending) ? $pending : null;
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function putPendingRegistration(string $token, array $pending): void
    {
        $seconds = max(1, (int) $pending['expires_at'] - now()->getTimestamp());
        Cache::put($this->registrationKey($token), $pending, $seconds);
    }

    private function registrationKey(string $token): string
    {
        return 'registration:'.hash('sha256', $token);
    }

    private function expiredRegistration(): JsonResponse
    {
        return $this->validationError([
            'registration_token' => ['This registration has expired. Start again.'],
        ]);
    }

    /**
     * Six digits, hashed at rest, one live code per user.
     */
    private function issueCode(User $user): void
    {
        $code = (string) random_int(100000, 999999);

        EmailVerificationCode::updateOrCreate(
            ['user_id' => $user->id],
            [
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'last_sent_at' => now(),
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            ],
        );

        $this->mail->trySend(
            $user->email,
            'Your CourtFlex verification code',
            [
                "Your verification code is {$code}.",
                'It expires in 15 minutes.',
            ],
        );
    }

    /**
     * Same wording as the SPA's own form, so the messages read naturally.
     *
     * @return array<string, list<string>>|null
     */
    private function passwordErrors(string $password, string $confirmation): ?array
    {
        if ($password === '' || strlen($password) < 8 || ! preg_match('/[A-Za-z]/', $password) || ! preg_match('/\d/', $password)) {
            return ['password' => ['Use at least 8 characters with a letter and a number.']];
        }

        if ($password !== $confirmation) {
            return ['password_confirmation' => ["The passwords don't match."]];
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validationError(array $errors): JsonResponse
    {
        return $this->errorResponse(
            message: 'The given data was invalid.',
            errors: $errors,
            code: Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
