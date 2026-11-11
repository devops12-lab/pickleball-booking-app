<?php

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Services\DatabaseBackup\BackupDriverFactory;
use App\Services\DatabaseBackup\BackupException;
use App\Services\DatabaseBackup\BinaryResolver;
use App\Services\DatabaseBackup\DatabaseTarget;
use App\Services\DatabaseBackup\ProcessRunner;
use App\Services\DatabaseBackupService;
use Database\Seeders\PlatformAdminSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->databasePath = useTemporaryFileDatabase();

    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlatformAdminSeeder::class);

    $this->backups = app(DatabaseBackupService::class);
});

afterEach(function (): void {
    DB::disconnect('sqlite');

    if (isset($this->databasePath) && is_file($this->databasePath)) {
        @unlink($this->databasePath);
    }
});

test('a real sqlite dump can be produced and restored with the native shell', function (): void {
    if (! app(BinaryResolver::class)->exists('sqlite3')) {
        $this->markTestSkipped('The sqlite3 shell is not installed on this machine.');
    }

    DB::statement('CREATE TABLE players (id integer primary key autoincrement, name text not null)');
    DB::table('players')->insert(['name' => 'Ada']);

    $backup = $this->backups->createRecord(BackupType::Manual);
    $completed = $this->backups->run($backup);

    $storedPath = Storage::disk('local')->path($completed->storage_path);

    expect($completed->status)->toBe(BackupStatus::Completed)
        ->and($completed->file_size)->toBeGreaterThan(0)
        ->and($completed->checksum_sha256)->toBe(hash_file('sha256', $storedPath))
        ->and(file_get_contents($storedPath))->toContain('CREATE TABLE players');

    $restorePath = tempnam(sys_get_temp_dir(), 'courtflex-restore-');

    config(['database.connections.sqlite.database' => $restorePath]);

    $driver = app(BackupDriverFactory::class)->forDriver('sqlite');

    $result = app(ProcessRunner::class)->run(
        $driver->restoreCommand(DatabaseTarget::fromConnection()),
        stdinPath: $storedPath,
        timeout: 120,
    );

    expect($result->successful())->toBeTrue();

    $pdo = new PDO('sqlite:'.$restorePath);
    $names = $pdo->query('SELECT name FROM players ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

    expect($names)->toBe(['Ada']);

    $pdo = null;
    Storage::disk('local')->delete($completed->storage_path);
    @unlink($restorePath);
});

test('a failing native dump marks the backup as failed and keeps the error', function (): void {
    if (DIRECTORY_SEPARATOR === '\\') {
        $failingBinary = tempnam(sys_get_temp_dir(), 'courtflex-fail-').'.bat';
        file_put_contents($failingBinary, "@echo off\r\necho boom: simulated failure 1>&2\r\nexit /b 1\r\n");
    } else {
        $failingBinary = tempnam(sys_get_temp_dir(), 'courtflex-fail-');
        file_put_contents($failingBinary, "#!/bin/sh\necho 'boom: simulated failure' >&2\nexit 1\n");
        chmod($failingBinary, 0755);
    }

    config(['database-backup.binaries.sqlite3' => $failingBinary]);

    $backup = $this->backups->createRecord(BackupType::Manual);

    try {
        expect(fn () => $this->backups->run($backup))->toThrow(BackupException::class);

        $backup->refresh();

        expect($backup->status)->toBe(BackupStatus::Failed)
            ->and($backup->error_message)->toContain('boom: simulated failure');
    } finally {
        Storage::disk('local')->delete($backup->storage_path);
        @unlink($failingBinary);
    }
});

test('a backup cannot be executed against a different database engine', function (): void {
    $backup = $this->backups->createRecord(BackupType::Manual);

    $backup->forceFill(['database_driver' => 'mysql'])->save();

    expect(fn () => $this->backups->run($backup))->toThrow(BackupException::class);

    expect($backup->fresh()->status)->toBe(BackupStatus::Failed);
});
