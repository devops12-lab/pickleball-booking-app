<?php

use App\Models\DatabaseBackup;
use App\Models\DatabaseBackupSchedule;
use App\Models\DatabaseRestoreLog;
use App\Services\DatabaseBackupRetentionService;
use App\Services\DatabaseBackupScheduleService;
use Database\Seeders\PlatformAdminSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlatformAdminSeeder::class);

    Storage::fake('local');

    $this->retention = app(DatabaseBackupRetentionService::class);

    app(DatabaseBackupScheduleService::class)->ensureDefaults();
});

/**
 * Create a stored, completed scheduled backup a number of days in the past.
 */
function makeScheduledBackup(string $type, int $daysAgo): DatabaseBackup
{
    $filename = "database_backup_{$type}_".Str::random(8).'.sql';
    $path = 'database-backups/'.$filename;

    Storage::disk('local')->put($path, 'PRAGMA foreign_keys=OFF;');

    return makeDatabaseBackup([
        'filename' => $filename,
        'backup_type' => $type,
        'storage_path' => $path,
        'file_size' => 22,
        'created_at' => now()->subDays($daysAgo),
        'completed_at' => now()->subDays($daysAgo),
    ]);
}

test('retention keeps the newest daily backups and deletes the rest', function (): void {
    $backups = collect();

    foreach (range(0, 8) as $index) {
        $backups->push(makeScheduledBackup('daily', 9 - $index));
    }

    $manual = makeDatabaseBackup([
        'storage_path' => 'database-backups/manual.sql',
        'created_at' => now()->subDays(30),
        'completed_at' => now()->subDays(30),
    ]);

    $summary = $this->retention->cleanup();

    expect($summary['deleted'])->toBe(2)
        ->and($summary['kept'])->toBe(7)
        ->and($summary['skipped'])->toBe(0)
        ->and($summary['errors'])->toBe([]);

    expect($backups[0]->fresh()->status->value)->toBe('deleted')
        ->and($backups[1]->fresh()->status->value)->toBe('deleted')
        ->and($backups[2]->fresh()->status->value)->toBe('completed')
        ->and($backups[8]->fresh()->status->value)->toBe('completed');

    expect($manual->fresh()->status->value)->toBe('completed');

    Storage::disk('local')->assertMissing($backups[0]->storage_path);
    Storage::disk('local')->assertExists($backups[8]->storage_path);
});

test('retention follows the retention count stored on the schedule', function (): void {
    app(DatabaseBackupScheduleService::class)
        ->update(
            DatabaseBackupSchedule::query()->where('frequency', 'daily')->firstOrFail(),
            ['retention_count' => 3]
        );

    foreach (range(0, 4) as $index) {
        makeScheduledBackup('daily', 5 - $index);
    }

    $summary = $this->retention->cleanup();

    expect($summary['deleted'])->toBe(2)
        ->and($summary['kept'])->toBe(3);

    expect(DatabaseBackup::query()->where('backup_type', 'daily')->where('status', 'completed')->count())->toBe(3);
    expect(DatabaseBackup::query()->where('backup_type', 'daily')->where('status', 'deleted')->count())->toBe(2);
});

test('a backup with a pending restore is never deleted', function (): void {
    $backups = collect();

    foreach (range(0, 8) as $index) {
        $backups->push(makeScheduledBackup('daily', 9 - $index));
    }

    $oldest = $backups[0];

    DatabaseRestoreLog::create([
        'database_backup_id' => $oldest->id,
        'original_filename' => $oldest->filename,
        'database_driver' => 'sqlite',
        'database_name' => 'in-memory',
        'restore_status' => 'pending',
    ]);

    $summary = $this->retention->cleanup();

    expect($summary['deleted'])->toBe(1)
        ->and($summary['skipped'])->toBe(1)
        ->and($summary['kept'])->toBe(7);

    expect($oldest->fresh()->status->value)->toBe('completed');
    Storage::disk('local')->assertExists($oldest->storage_path);
});

test('retention never removes manual or uploaded backups', function (): void {
    foreach (range(0, 4) as $index) {
        makeScheduledBackup('manual', 40 - $index);
        makeScheduledBackup('uploaded', 40 - $index);
    }

    $summary = $this->retention->cleanup();

    expect($summary['deleted'])->toBe(0)
        ->and(DatabaseBackup::query()->count())->toBe(10);

    expect(DatabaseBackup::query()->where('status', 'completed')->count())->toBe(10);
});

test('the cleanup command reports the retention summary', function (): void {
    foreach (range(0, 8) as $index) {
        makeScheduledBackup('daily', 9 - $index);
    }

    $this->artisan('database:backup-cleanup')
        ->expectsOutputToContain('Retention cleanup finished')
        ->assertSuccessful();
});
