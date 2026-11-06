<?php

use App\Models\User;
use App\Services\Auth\GoogleAuthenticationService;
use Database\Seeders\PlatformAdminSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlatformAdminSeeder::class);

    // Explicitly configure allowed origins for deterministic test runs
    Config::set('cors.allowed_origins', [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:3000',
    ]);
    Config::set('cors.supports_credentials', true);
});

afterEach(function (): void {
    GoogleAuthenticationService::stopFaking();
});

/*
|--------------------------------------------------------------------------
| Preflight OPTIONS and Origin Tests
|--------------------------------------------------------------------------
*/
describe('CORS Preflight and Origin Validation', function (): void {
    test('preflight OPTIONS request from authorized React origin succeeds', function (): void {
        $response = $this->options('/api/v1/players/auth/login', [], [
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Authorization, Content-Type, X-Tenant-ID',
        ]);

        expect($response->getStatusCode())->toBeIn([200, 204]);

        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $allowHeaders = $response->headers->get('Access-Control-Allow-Headers');
        expect(strtolower($allowHeaders))->toContain('authorization')
            ->and(strtolower($allowHeaders))->toContain('content-type')
            ->and(strtolower($allowHeaders))->toContain('x-tenant-id');
    });

    test('preflight OPTIONS request from unauthorized origin is rejected', function (): void {
        $response = $this->options('/api/v1/players/auth/login', [], [
            'Origin' => 'http://malicious-attacker.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Authorization, Content-Type',
        ]);

        expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
    });

    test('actual GET request from React origin receives CORS headers', function (): void {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Accept' => 'application/json',
        ])->getJson('/api/v1/public/facilities');

        $response->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    });

    test('preflight OPTIONS works across all role-specific authentication prefixes', function (string $endpoint): void {
        $response = $this->options($endpoint, [], [
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type, Accept',
        ]);

        expect($response->getStatusCode())->toBeIn([200, 204]);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    })->with([
        '/api/v1/admin/auth/login',
        '/api/v1/owners/auth/login',
        '/api/v1/staff/auth/login',
        '/api/v1/players/auth/login',
        '/api/v1/organizers/auth/login',
    ]);

    test('preflight OPTIONS works on tenant-scoped business routes with X-Tenant-ID header', function (): void {
        $response = $this->options('/api/v1/owners/courts', [], [
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Authorization, X-Tenant-ID, Content-Type',
        ]);

        expect($response->getStatusCode())->toBeIn([200, 204]);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');

        $allowHeaders = strtolower($response->headers->get('Access-Control-Allow-Headers'));
        expect($allowHeaders)->toContain('x-tenant-id');
    });
});

/*
|--------------------------------------------------------------------------
| Authentication and React Compatibility Tests
|--------------------------------------------------------------------------
*/
describe('Authentication & React Compatibility', function (): void {
    test('authenticated player request with Bearer token from React origin succeeds', function (): void {
        $player = User::create([
            'name' => 'React Player',
            'email' => 'react.player@courtflex.test',
            'password' => bcrypt('Password123!'),
            'account_status' => 'active',
        ]);
        $token = $player->createToken('player_auth_token')->plainTextToken;

        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/players/profile');

        $response->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertJsonPath('data.email', 'react.player@courtflex.test');
    });

    test('invalid Bearer token returns 401 standard JSON envelope with CORS headers', function (): void {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Authorization' => 'Bearer invalid-token-xyz',
            'Accept' => 'application/json',
        ])->getJson('/api/v1/players/profile');

        $response->assertStatus(401)
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    });

    test('unauthorized role request returns 403 standard JSON envelope with CORS headers', function (): void {
        $player = User::create([
            'name' => 'Regular Player',
            'email' => 'regular@courtflex.test',
            'password' => bcrypt('Password123!'),
            'account_status' => 'active',
        ]);
        $token = $player->createToken('player_token')->plainTextToken;

        // Player attempting to access System Admin dashboard
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/admin/dashboard');

        $response->assertStatus(403)
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors']);
    });

    test('google oauth supports standard React GIS credential payload', function (): void {
        GoogleAuthenticationService::fake([
            'sub' => 'google-react-gis-id',
            'email' => 'react.gis@gmail.com',
            'name' => 'React GIS User',
        ]);

        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Accept' => 'application/json',
        ])->postJson('/api/v1/players/auth/google', [
            'credential' => 'mock-google-id-token-from-react-gis',
        ]);

        $response->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertJsonPath('data.user.active_role', 'player')
            ->assertJsonPath('data.user.email', 'react.gis@gmail.com');
    });

    test('system admin google oauth endpoint remains strictly non-existent', function (): void {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Accept' => 'application/json',
        ])->postJson('/api/v1/admin/auth/google', [
            'credential' => 'dummy-admin-token',
        ]);

        $response->assertStatus(404)
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertJsonPath('success', false);
    });
});
