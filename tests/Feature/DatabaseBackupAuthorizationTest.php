<?php

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

test('guests cannot access the database backup endpoints', function (): void {
    $this->getJson('/api/v1/admin/database-backups')->assertUnauthorized();
    $this->getJson('/api/v1/admin/backup-schedules')->assertUnauthorized();
});

test('a regular player is denied access to database backups', function (): void {
    $player = User::create([
        'name' => 'Regular Player',
        'email' => 'backup-player@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $this->actingAs($player)->getJson('/api/v1/admin/database-backups')->assertForbidden();
    $this->actingAs($player)->getJson('/api/v1/admin/backup-schedules')->assertForbidden();
    $this->actingAs($player)->postJson('/api/v1/admin/database-backups')->assertForbidden();
});

test('a system administrator can list database backups', function (): void {
    $this->actingAs(databaseBackupAdmin())
        ->getJson('/api/v1/admin/database-backups')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
});

test('a system administrator can read the backup schedule defaults', function (): void {
    $response = $this->actingAs(databaseBackupAdmin())
        ->getJson('/api/v1/admin/backup-schedules')
        ->assertOk();

    $response->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.frequency', 'daily')
        ->assertJsonPath('data.0.retention_count', 7);
});

test('a user without the system admin role is denied even when the backup feature is enabled', function (): void {
    $player = User::create([
        'name' => 'Active Player',
        'email' => 'backup-active-player@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $this->actingAs($player)
        ->getJson('/api/v1/admin/database-backups/monitoring')
        ->assertForbidden();
});
