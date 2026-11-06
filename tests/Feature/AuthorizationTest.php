<?php

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlatformAdminSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlatformAdminSeeder::class);
});

test('system admin can access platform dashboard', function (): void {
    $admin = User::where('email', 'admin@courtflex.test')->first();

    $response = $this->actingAs($admin)->getJson('/api/v1/admin/dashboard');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'total_tenants',
                'active_tenants',
                'total_users',
                'total_bookings',
                'total_revenue',
            ],
        ]);
});

test('regular player cannot access platform dashboard', function (): void {
    $player = User::create([
        'name' => 'Regular Player',
        'email' => 'player@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $response = $this->actingAs($player)->getJson('/api/v1/admin/dashboard');

    $response->assertStatus(403)
        ->assertJsonPath('success', false);
});

test('system admin can update tenant status', function (): void {
    $admin = User::where('email', 'admin@courtflex.test')->first();
    $tenant = Tenant::first();

    $response = $this->actingAs($admin)
        ->patchJson("/api/v1/admin/tenants/{$tenant->id}/status", [
            'status' => 'suspended',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'suspended');

    expect($tenant->fresh()->status)->toBe('suspended');
});
