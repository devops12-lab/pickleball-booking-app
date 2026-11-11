<?php

use App\Mail\CourtFlexMail;
use App\Models\User;
use App\Services\Mail\EmailSettingsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function chatRegistrationSettings(bool $verificationRequired): void
{
    app(EmailSettingsService::class)->save([
        'enabled' => true,
        'host' => 'smtp.test',
        'port' => 587,
        'username' => '',
        'encryption' => 'tls',
        'from_address' => 'no-reply@courtflex.test',
        'from_name' => 'CourtFlex',
        'verification_required' => $verificationRequired,
    ]);
}

/**
 * @return array{token: string, code: string}
 */
function startChatRegistration(string $email, bool $verificationRequired = true): array
{
    chatRegistrationSettings($verificationRequired);

    $response = test()
        ->withHeaders([
            'Origin' => 'http://localhost:5174',
            'Referer' => 'http://localhost:5174/',
        ])
        ->postJson('/api/v1/auth/register/start', [
            'name' => 'Chat Player',
            'email' => $email,
            'phone' => '',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
        ->assertCreated()
        ->assertJsonPath('data.verification_required', $verificationRequired);

    $code = '';

    if ($verificationRequired) {
        $mail = Mail::sent(CourtFlexMail::class)->first();
        expect($mail)->not->toBeNull();
        preg_match('/\b(\d{6})\b/', $mail->lines[0], $matches);
        $code = $matches[1] ?? '';
        expect($code)->toHaveLength(6);
    }

    return [
        'token' => (string) $response->json('data.registration_token'),
        'code' => $code,
    ];
}

test('the chat verifies the email before creating the account and leaves the player signed out', function (): void {
    Mail::fake();

    $started = startChatRegistration('chat-verify@courtflex.test');

    test()->postJson('/api/v1/auth/register/verify', [
        'registration_token' => $started['token'],
        'code' => $started['code'],
    ])->assertOk()->assertJsonPath('data.verified', true);

    expect(User::where('email', 'chat-verify@courtflex.test')->exists())->toBeFalse();

    test()->postJson('/api/v1/auth/register/complete', [
        'registration_token' => $started['token'],
        'accept_terms' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('data.created', true)
        ->assertJsonPath('data.email', 'chat-verify@courtflex.test')
        ->assertJsonPath('message', 'Account created. You can now sign in.');

    $user = User::where('email', 'chat-verify@courtflex.test')->firstOrFail();

    expect($user->email_verified_at)->not->toBeNull();
    test()->assertGuest('web');
});

test('registration skips the email code when the Super Admin turns verification off', function (): void {
    Mail::fake();

    $started = startChatRegistration('chat-skip@courtflex.test', false);

    test()->postJson('/api/v1/auth/register/complete', [
        'registration_token' => $started['token'],
        'accept_terms' => true,
    ])->assertCreated()->assertJsonPath('data.created', true);

    $user = User::where('email', 'chat-skip@courtflex.test')->firstOrFail();

    expect($user->email_verified_at)->not->toBeNull();
    Mail::assertNothingSent();
    test()->assertGuest('web');
});

test('the chat email check reports an address that already has an account', function (): void {
    User::create([
        'name' => 'Existing Player',
        'email' => 'already-here@courtflex.test',
        'password' => 'Password123!',
        'account_status' => 'active',
        'email_verified_at' => now(),
    ]);

    test()->postJson('/api/v1/auth/register/check-email', [
        'email' => 'already-here@courtflex.test',
    ])->assertOk()->assertJsonPath('data.available', false);

    test()->postJson('/api/v1/auth/register/check-email', [
        'email' => 'new-player@courtflex.test',
    ])->assertOk()->assertJsonPath('data.available', true);
});

test('an account is not created until the terms are accepted', function (): void {
    Mail::fake();

    $started = startChatRegistration('chat-terms@courtflex.test');

    test()->postJson('/api/v1/auth/register/verify', [
        'registration_token' => $started['token'],
        'code' => $started['code'],
    ])->assertOk();

    test()->postJson('/api/v1/auth/register/complete', [
        'registration_token' => $started['token'],
        'accept_terms' => false,
    ])->assertUnprocessable()->assertJsonValidationErrors('accept_terms');

    expect(User::where('email', 'chat-terms@courtflex.test')->exists())->toBeFalse();
});
