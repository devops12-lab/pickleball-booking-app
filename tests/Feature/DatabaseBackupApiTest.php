<?php

use App\Jobs\CreateDatabaseBackupJob;
use App\Models\DatabaseBackup;
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
});

afterEach(function (): void {
    DB::disconnect('sqlite');

    if (isset($this->databasePath) && is_file($this->databasePath)) {
        @unlink($this->databasePath);
    }
});

test('an administrator can queue a manual backup', function (): void {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/database-backups', ['notes' => 'before upgrade']);

    $response->assertAccepted()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.backup_type', 'manual')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonStructure(['meta' => ['operation_id']]);

    expect($response->json('meta.operation_id'))->toBeString()->not->toBe('');

    Queue::assertPushed(CreateDatabaseBackupJob::class);

    $this->assertDatabaseHas('database_backups', [
        'backup_type' => 'manual',
        'status' => 'pending',
    ]);
});

test('an administrator can upload an external sqlite dump', function (): void {
    $content = "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\nCREATE TABLE players (id integer primary key, name text);\nCOMMIT;\n";

    $file = UploadedFile::fake()->createWithContent('external-dump.sql', $content);

    $response = $this->actingAs($this->admin)
        ->post('/api/v1/admin/database-backups/upload', ['file' => $file], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.database_driver', 'sqlite')
        ->assertJsonPath('data.original_filename', 'external-dump.sql');

    $backup = DatabaseBackup::query()->latest('id')->firstOrFail();

    expect($backup->file_size)->toBe(strlen($content));
    expect($backup->checksum_sha256)->toBe(hash('sha256', $content));

    Queue::assertNothingPushed();
    Storage::disk('local')->assertExists($backup->storage_path);
});

test('an upload with a disallowed extension is rejected', function (): void {
    $file = UploadedFile::fake()->createWithContent('dump.txt', 'select 1;');

    $this->actingAs($this->admin)
        ->post('/api/v1/admin/database-backups/upload', ['file' => $file], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    $this->assertDatabaseCount('database_backups', 0);
});

test('an unreadable dump is stored with an unknown database driver', function (): void {
    $file = UploadedFile::fake()->createWithContent('mystery.sql', 'select 1;');

    $this->actingAs($this->admin)
        ->post('/api/v1/admin/database-backups/upload', ['file' => $file], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.database_driver', 'unknown');
});

test('a completed backup can be downloaded', function (): void {
    $content = "PRAGMA foreign_keys=OFF;\n-- dump body\n";
    $path = 'database-backups/download.sql';

    Storage::disk('local')->put($path, $content);

    $backup = makeDatabaseBackup([
        'filename' => 'download.sql',
        'storage_path' => $path,
        'file_size' => strlen($content),
        'checksum_sha256' => hash('sha256', $content),
    ]);

    $response = $this->actingAs($this->admin)
        ->get('/api/v1/admin/database-backups/'.$backup->id.'/download');

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/sql');
    expect($response->headers->get('content-disposition'))->toContain('download.sql');
    expect($response->streamedContent())->toBe($content);
});

test('a pending backup cannot be downloaded', function (): void {
    $backup = makeDatabaseBackup(['status' => 'pending', 'storage_path' => 'database-backups/pending.sql']);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/database-backups/'.$backup->id.'/download')
        ->assertUnprocessable();
});

test('a completed backup download fails when the file is missing', function (): void {
    $backup = makeDatabaseBackup(['storage_path' => 'database-backups/gone.sql']);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/database-backups/'.$backup->id.'/download')
        ->assertNotFound();
});

test('a pending backup cannot be deleted', function (): void {
    $backup = makeDatabaseBackup(['status' => 'processing']);

    $this->actingAs($this->admin)
        ->deleteJson('/api/v1/admin/database-backups/'.$backup->id)
        ->assertUnprocessable();

    expect($backup->fresh()->status->value)->toBe('processing');
});

test('a completed backup can be deleted and its file removed', function (): void {
    $path = 'database-backups/deletable.sql';

    Storage::disk('local')->put($path, "PRAGMA foreign_keys=OFF;\n");

    $backup = makeDatabaseBackup(['storage_path' => $path]);

    $this->actingAs($this->admin)
        ->deleteJson('/api/v1/admin/database-backups/'.$backup->id)
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($backup->fresh()->status->value)->toBe('deleted');
    Storage::disk('local')->assertMissing($path);
});

test('the history can be filtered by status and type', function (): void {
    makeDatabaseBackup(['backup_type' => 'manual', 'status' => 'completed']);
    makeDatabaseBackup([
        'filename' => 'daily_failed.sql',
        'backup_type' => 'daily',
        'status' => 'failed',
        'error_message' => 'boom',
    ]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/database-backups?status=failed')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.status', 'failed');

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/database-backups?type=daily')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.backup_type', 'daily');
});

test('the monitoring endpoint reports backup and restore health', function (): void {
    makeDatabaseBackup(['status' => 'completed', 'file_size' => 2048]);
    makeDatabaseBackup([
        'filename' => 'daily_failed.sql',
        'backup_type' => 'daily',
        'status' => 'failed',
        'completed_at' => now(),
        'error_message' => 'disk full',
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/database-backups/monitoring')
        ->assertOk()
        ->assertJsonPath('data.is_enabled', true)
        ->assertJsonPath('data.storage_bytes', 2048)
        ->assertJsonPath('data.latest_failed_backup.error_message', 'disk full');

    $response->assertJsonStructure([
        'data' => [
            'is_enabled',
            'restore_enabled',
            'storage_bytes',
            'backups_by_status',
            'backups_by_type',
            'latest_backup',
            'latest_failed_backup',
            'next_scheduled_backup',
            'active_restore',
            'latest_restore',
        ],
    ]);
});

test('the audit log for a backup can be listed', function (): void {
    $path = 'database-backups/audited.sql';
    Storage::disk('local')->put($path, "PRAGMA foreign_keys=OFF;\n");

    $backup = makeDatabaseBackup(['storage_path' => $path]);

    $this->actingAs($this->admin)
        ->deleteJson('/api/v1/admin/database-backups/'.$backup->id)
        ->assertOk();

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/database-backups/audit-logs?backup_id='.$backup->id)
        ->assertOk()
        ->assertJsonPath('data.0.action', 'backup.deleted');
});
