<?php

use App\Models\User;
use App\Services\Auth\GoogleAuthenticationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function (): void {
    GoogleAuthenticationService::stopFaking();
});

test('player can register successfully', function (): void {
    $response = $this->postJson('/api/v1/players/auth/register', [
        'name' => 'John Doe',
        'email' => 'john@courtflex.test',
        'password' => 'Secret123!',
        'password_confirmation' => 'Secret123!',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'user' => ['id', 'name', 'email', 'account_status'],
                'token',
                'token_type',
            ],
        ]);

    $this->assertDatabaseHas('users', [
        'email' => 'john@courtflex.test',
        'account_status' => 'active',
    ]);
});

test('registration validates input', function (): void {
    $response = $this->postJson('/api/v1/players/auth/register', [
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'short',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['errors']);
});

test('player can login with valid credentials', function (): void {
    $user = User::create([
        'name' => 'Jane Player',
        'email' => 'jane@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $response = $this->postJson('/api/v1/players/auth/login', [
        'email' => 'jane@courtflex.test',
        'password' => 'Password123!',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', 'jane@courtflex.test')
        ->assertJsonStructure(['data' => ['token']]);
});

test('login fails with invalid credentials', function (): void {
    User::create([
        'name' => 'Jane Player',
        'email' => 'jane@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $response = $this->postJson('/api/v1/players/auth/login', [
        'email' => 'jane@courtflex.test',
        'password' => 'WrongPassword!',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('success', false);
});

test('suspended player cannot login', function (): void {
    User::create([
        'name' => 'Suspended Player',
        'email' => 'banned@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'suspended',
    ]);

    $response = $this->postJson('/api/v1/players/auth/login', [
        'email' => 'banned@courtflex.test',
        'password' => 'Password123!',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);
});

test('authenticated player can view profile', function (): void {
    $user = User::create([
        'name' => 'Jane Player',
        'email' => 'jane@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/players/auth/me');

    $response->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.email', 'jane@courtflex.test');
});

test('authenticated player can logout', function (): void {
    $user = User::create([
        'name' => 'Jane Player',
        'email' => 'jane@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    $token = $user->createToken('test_token')->plainTextToken;

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/players/auth/logout');

    $response->assertOk()
        ->assertJsonPath('success', true);

    expect($user->tokens()->count())->toBe(0);
});

test('player can authenticate via google', function (): void {
    GoogleAuthenticationService::fake([
        'sub' => 'google-uid-12345',
        'email' => 'google.user@courtflex.test',
        'name' => 'Google Pickleballer',
    ]);

    $response = $this->postJson('/api/v1/players/auth/google', [
        'id_token' => 'valid-mock-token',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', 'google.user@courtflex.test');

    $this->assertDatabaseHas('users', [
        'email' => 'google.user@courtflex.test',
    ]);
});
