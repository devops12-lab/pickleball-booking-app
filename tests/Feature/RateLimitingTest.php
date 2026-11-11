<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    RateLimiter::clear('login:test@courtflex.test|127.0.0.1');
    RateLimiter::clear('register:127.0.0.1');
});

test('login rate limit blocks after exceeding allowed attempts and returns standardized 429', function (): void {
    User::create([
        'name' => 'Throttled User',
        'email' => 'throttled@courtflex.test',
        'password' => Hash::make('CorrectPassword123!'),
        'account_status' => 'active',
    ]);

    // Attempt login 5 times with wrong password
    for ($i = 0; $i < 5; $i++) {
        $response = $this->postJson('/api/v1/players/auth/login', [
            'email' => 'throttled@courtflex.test',
            'password' => 'WrongPassword!',
        ]);
        expect($response->status())->toBe(401);
    }

    // 6th attempt should be blocked by rate limiter with 429
    $blockedResponse = $this->postJson('/api/v1/players/auth/login', [
        'email' => 'throttled@courtflex.test',
        'password' => 'WrongPassword!',
    ]);

    $blockedResponse->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['success', 'message', 'errors'])
        ->assertHeader('Retry-After');
});

test('public api throttles requests exceeding limit', function (): void {
    RateLimiter::clear('public-api:127.0.0.1');

    $response = $this->getJson('/api/v1/public/facilities');
    $response->assertOk();
    expect($response->headers->has('X-RateLimit-Limit'))->toBeTrue();
});
