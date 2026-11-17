<?php

use App\Models\DatabaseBackup;
use App\Models\PlayerStatistic;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Build a database backup record with sensible defaults for tests.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeDatabaseBackup(array $attributes = []): DatabaseBackup
{
    return DatabaseBackup::create(array_merge([
        'filename' => 'database_backup_sqlite_manual_2026-01-01_00-00-00.sql',
        'original_filename' => null,
        'database_driver' => 'sqlite',
        'database_name' => 'in-memory',
        'backup_type' => 'manual',
        'storage_disk' => 'local',
        'storage_path' => 'database-backups/database_backup_sqlite_manual_2026-01-01_00-00-00.sql',
        'file_size' => 0,
        'checksum_sha256' => null,
        'status' => 'completed',
        'started_at' => now(),
        'completed_at' => now(),
        'duration_seconds' => 1,
        'metadata' => null,
    ], $attributes));
}

/**
 * Point the application at a throwaway, file based SQLite database.
 *
 * In-memory databases cannot be dumped or restored, so the backup feature
 * tests that exercise the real orchestration need a file based database.
 */
function useTemporaryFileDatabase(): string
{
    $path = tempnam(sys_get_temp_dir(), 'courtflex-backup-test-');

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $path,
        'cache.default' => 'array',
    ]);

    DB::purge('sqlite');

    return $path;
}

/**
 * Create a system administrator with platform-admin tenant link.
 *
 * Requires RolesAndPermissionsSeeder and PlatformAdminSeeder to be run first.
 */
function createSystemAdmin(string $email = 'admin@courtflex.test', string $name = 'CourtFlex Administrator'): User
{
    $admin = User::firstOrCreate(
        ['email' => $email],
        [
            'name' => $name,
            'password' => Hash::make('Password123!'),
            'account_status' => 'active',
            'email_verified_at' => now(),
        ]
    );

    PlayerStatistic::firstOrCreate(
        ['user_id' => $admin->id],
        ['current_rating' => 1500.00]
    );

    $adminRole = Role::where('name', 'system_admin')->firstOrFail();
    $systemTenant = Tenant::where('slug', 'platform-admin')->firstOrFail();

    TenantUser::firstOrCreate(
        [
            'tenant_id' => $systemTenant->id,
            'user_id' => $admin->id,
        ],
        [
            'role_id' => $adminRole->id,
            'status' => 'active',
        ]
    );

    return $admin;
}

/**
 * Resolve or create the system administrator for database backup tests.
 */
function databaseBackupAdmin(): User
{
    return User::query()->where('email', 'admin@courtflex.test')->first()
        ?? createSystemAdmin();
}
