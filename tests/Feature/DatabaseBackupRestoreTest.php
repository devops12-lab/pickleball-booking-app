<?php

use App\Jobs\RestoreDatabaseBackupJob;
use App\Models\DatabaseBackup;
use App\Models\DatabaseRestoreLog;
use App\Services\DatabaseBackup\DatabaseTarget;
use Database\Seeders\PlatformAdminSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->databasePath = useTemporaryFileDatabase();

    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlatformAdminSeeder::class);

    Storage::fake('local');
    Queue::fake();

    $this->admin = databaseBackupAdmin();
    $this->targetName = DatabaseTarget::fromConnection()->database;

    $this->makeRestoreBackup = function (array $overrides = []): DatabaseBackup {
        $content = "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\nCOMMIT;\n";
        $path = 'database-backups/restore-source.sql';

        Storage::disk('local')->put($path, $content);

        return makeDatabaseBackup(array_merge([
            'filename' => 'restore-source.sql',
            'storage_path' => $path,
            'file_size' => strlen($content),
            'checksum_sha256' => hash('sha256', $content),
            'database_name' => $this->targetName,
        ], $overrides));
    };
});

afterEach(function (): void {
    DB::disconnect('sqlite');

    if (isset($this->databasePath) && is_file($this->databasePath)) {
        @unlink($this->databasePath);
    }
});

function restorePayload(string $confirmation, bool $acknowledge = true): array
{
    return ['confirmation' => $confirmation, 'acknowledge' => $acknowledge];
}

test('a completed backup queues a restore when the confirmation matches', function (): void {
    $backup = ($this->makeRestoreBackup)();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/database-backups/'.$backup->id.'/restore', restorePayload($this->targetName));

    $response->assertAccepted()
        ->assertJsonPath('data.restore_status', 'pending')
        ->assertJsonPath('data.database_name', $this->targetName)
        ->assertJsonStructure(['meta' => ['operation_id']]);

    Queue::assertPushed(RestoreDatabaseBackupJob::class);

    $this->assertDatabaseHas('database_restore_logs', [
        'database_backup_id' => $backup->id,
        'restore_status' => 'pending',
        'executed_by' => $this->admin->id,
    ]);
});

test('a restore is rejected when the typed database name does not match', function (): void {
    $backup = ($this->makeRestoreBackup)();

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/database-backups/'.$backup->id.'/restore', restorePayload('some-other-database'))
        ->assertUnprocessable()
        ->assertJsonPath('success', false);

    $this->assertDatabaseCount('database_restore_logs', 0);
    Queue::assertNothingPushed();
});

test('a restore is rejected when the acknowledgement is missing', function (): void {
    $backup = ($this->makeRestoreBackup)();

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/database-backups/'.$backup->id.'/restore', [
            'confirmation' => $this->targetName,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('acknowledge');
});

test('a restore is rejected when the acknowledgement is not accepted', function (): void {
    $backup = ($this->makeRestoreBackup)();

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/database-backups/'.$backup->id.'/restore', restorePayload($this->targetName, false))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('acknowledge');
});

test('a restore is rejected when the engine does not match the active database', function (): void {
    $backup = ($this->makeRestoreBackup)(['database_driver' => 'mysql']);

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/database-backups/'.$backup->id.'/restore', restorePayload($this->targetName))
        ->assertUnprocessable()
        ->assertJsonPath('success', false);

    $this->assertDatabaseCount('database_restore_logs', 0);
});

test('a restore is rejected when the checksum does not match', function (): void {
    $backup = ($this->makeRestoreBackup)(['checksum_sha256' => str_repeat('a', 64)]);

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/database-backups/'.$backup->id.'/restore', restorePayload($this->targetName))
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'checksum'));

    $this->assertDatabaseCount('database_restore_logs', 0);
});

test('a restore is rejected when the backup file is missing', function (): void {
    $backup = ($this->makeRestoreBackup)();

    Storage::disk('local')->delete($backup->storage_path);

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/database-backups/'.$backup->id.'/restore', restorePayload($this->targetName))
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'missing'));
});

test('a restore is rejected when the backup is not completed', function (): void {
    $backup = ($this->makeRestoreBackup)(['status' => 'failed']);

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/database-backups/'.$backup->id.'/restore', restorePayload($this->targetName))
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'completed'));
});

test('a restore is rejected while another restore is pending', function (): void {
    $backup = ($this->makeRestoreBackup)();

    DatabaseRestoreLog::create([
        'database_backup_id' => $backup->id,
        'original_filename' => $backup->filename,
        'database_driver' => 'sqlite',
        'database_name' => $this->targetName,
        'restore_status' => 'pending',
        'executed_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/database-backups/'.$backup->id.'/restore', restorePayload($this->targetName))
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'already'));

    $this->assertDatabaseCount('database_restore_logs', 1);
});

test('a restore is rejected when restores are disabled', function (): void {
    config(['database-backup.restore.enabled' => false]);

    $backup = ($this->makeRestoreBackup)();

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/database-backups/'.$backup->id.'/restore', restorePayload($this->targetName))
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'disabled'));
});

test('a restore is rejected for in-memory sqlite databases', function (): void {
    config(['database.connections.sqlite.database' => ':memory:']);

    $backup = ($this->makeRestoreBackup)();

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/database-backups/'.$backup->id.'/restore', restorePayload('in-memory'))
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'In-memory'));
});

test('an uploaded dump can be restored in one explicit step', function (): void {
    $content = "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\nCOMMIT;\n";

    $file = UploadedFile::fake()->createWithContent('external.sql', $content);

    $response = $this->actingAs($this->admin)
        ->post('/api/v1/admin/database-backups/restore-upload', [
            'file' => $file,
            'confirmation' => $this->targetName,
            'acknowledge' => true,
        ], ['Accept' => 'application/json']);

    $response->assertAccepted()
        ->assertJsonPath('data.restore_status', 'pending')
        ->assertJsonStructure(['meta' => ['operation_id', 'backup_id']]);

    Queue::assertPushed(RestoreDatabaseBackupJob::class);
});
