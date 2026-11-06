<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::create([
        'name' => 'Standard User',
        'email' => 'standard@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
        'mfa_enabled' => true,
        'mfa_secret' => 'SECRET-123456',
    ]);
});

test('unauthenticated request returns standardized 401 json structure', function (): void {
    $response = $this->getJson('/api/v1/players/auth/me');

    $response->assertStatus(401)
        ->assertJson([
            'success' => false,
            'message' => 'Unauthenticated.',
        ]);
});

test('not found request returns standardized 404 json structure', function (): void {
    $response = $this->getJson('/api/v1/non-existent-endpoint');

    $response->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['success', 'message']);
});

test('validation error returns standardized 422 json structure', function (): void {
    $response = $this->postJson('/api/v1/players/auth/register', [
        'name' => '',
        'email' => 'invalid-email',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'The given data was invalid.',
        ])
        ->assertJsonStructure(['success', 'message', 'errors']);
});

test('user resource excludes sensitive attributes like password and mfa secret', function (): void {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/players/profile');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $this->user->id);

    $json = $response->json();

    expect(isset($json['data']['password']))->toBeFalse()
        ->and(isset($json['data']['mfa_secret']))->toBeFalse()
        ->and(isset($json['data']['remember_token']))->toBeFalse();
});
