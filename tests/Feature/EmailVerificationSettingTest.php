<?php

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\Mail\EmailSettingsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function registerPlayer(string $email): \Illuminate\Testing\TestResponse
{
    return test()
        ->withHeaders([
            'Origin' => 'http://localhost:5174',
            'Referer' => 'http://localhost:5174/',
        ])
        ->postJson('/api/v1/auth/register', [
        'name' => 'Verification Tester',
        'email' => $email,
        'phone' => '',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
            'accept_terms' => true,
        ]);
}

test('a new player is verified immediately when the Super Admin turns verification off', function (): void {
    app(EmailSettingsService::class)->save([
        'enabled' => false,
        'host' => '',
        'port' => 587,
        'username' => '',
        'encryption' => 'tls',
        'from_address' => '',
        'from_name' => 'CourtFlex',
        'verification_required' => false,
    ]);

    $response = registerPlayer('skip-verification@courtflex.test');

    $response->assertCreated()
        ->assertJsonPath('data.email_verified', true)
        ->assertJsonPath('message', 'Account created.');

    $user = User::where('email', 'skip-verification@courtflex.test')->firstOrFail();

    expect($user->email_verified_at)->not->toBeNull();
    expect(EmailVerificationCode::where('user_id', $user->id)->count())->toBe(0);
});

test('a new player still needs the emailed code when verification is required', function (): void {
    app(EmailSettingsService::class)->save([
        'enabled' => false,
        'host' => '',
        'port' => 587,
        'username' => '',
        'encryption' => 'tls',
        'from_address' => '',
        'from_name' => 'CourtFlex',
        'verification_required' => true,
    ]);

    $response = registerPlayer('verify-me@courtflex.test');

    $response->assertCreated()
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('message', 'Account created. Check your email for the six-digit code.');

    $user = User::where('email', 'verify-me@courtflex.test')->firstOrFail();

    expect($user->email_verified_at)->toBeNull();
    expect(EmailVerificationCode::where('user_id', $user->id)->count())->toBe(1);
});
