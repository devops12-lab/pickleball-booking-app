<?php

namespace App\Services;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Enums\DatabaseBackupAction;
use App\Models\DatabaseBackup;
use App\Models\User;
use App\Notifications\DatabaseBackupNotification;
use App\Services\DatabaseBackup\BackupDriverFactory;
use App\Services\DatabaseBackup\BackupException;
use App\Services\DatabaseBackup\DatabaseTarget;
use App\Services\DatabaseBackup\ProcessRunner;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Creates, uploads and deletes database backup records.
 *
 * Native utilities stream straight to a file; SQL contents are never held in
 * PHP memory and never sent to a queue payload.
 */
class DatabaseBackupService
{
    public function __construct(
        protected BackupDriverFactory $drivers,
        protected DatabaseBackupStorageService $storage,
        protected ProcessRunner $processRunner,
        protected DatabaseBackupAuditService $audit,
    ) {}

    /**
     * Create a pending backup record for the active connection.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function createRecord(BackupType $type, ?int $userId = null, array $metadata = []): DatabaseBackup
    {
        $this->ensureEnabled();

        $target = DatabaseTarget::fromConnection();

        if (! $target->isBackupable()) {
            throw new BackupException(
                'In-memory SQLite databases cannot be backed up. Configure a file based SQLite database or use MySQL / PostgreSQL.'
            );
        }

        $filename = $this->storage->generateFilename($target, $type);

        return DatabaseBackup::create([
            'filename' => $filename,
            'database_driver' => $target->engine,
            'database_name' => $target->database,
            'backup_type' => $type,
            'storage_disk' => $this->storage->diskName(),
            'storage_path' => $this->storage->relativePath($filename),
            'status' => BackupStatus::Pending,
            'created_by' => $userId,
            'metadata' => array_merge([
                'engine_label' => $target->engineLabel(),
                'requested_at' => CarbonImmutable::now()->toIso8601String(),
            ], $metadata),
        ]);
    }

    /**
     * Execute a pending backup record.
     *
     * The operation is guarded by a cache lock so two workers can never dump
     * or restore at the same time, even when queued twice.
     */
    public function run(DatabaseBackup $backup): DatabaseBackup
    {
        if ($backup->status === BackupStatus::Completed) {
            return $backup;
        }

        if ($backup->status !== BackupStatus::Pending) {
            throw new BackupException('Only pending backups can be executed.');
        }

        $lock = Cache::lock(
            "database-backup:run:{$backup->id}",
            (int) config('database-backup.locks.backup_ttl', 7200)
        );

        if (! $lock->get()) {
            throw new BackupException('Another backup operation is already running.');
        }

        $target = DatabaseTarget::fromConnection();
        $startedAt = CarbonImmutable::now();

        try {
            if ($target->engine !== $backup->database_driver) {
                throw new BackupException(
                    "This backup was created for {$backup->database_driver} but the application now uses {$target->engine}."
                );
            }

            $driver = $this->drivers->forDriver($target->engine);
            $driver->verify();

            $backup->forceFill([
                'status' => BackupStatus::Processing,
                'started_at' => $startedAt,
                'error_message' => null,
            ])->save();

            [$outputPath, $stagingPath] = $this->prepareOutput($backup);

            try {
                $result = $this->processRunner->run(
                    command: $driver->dumpCommand($target, $outputPath),
                    environment: $driver->environment($target),
                    stdoutPath: $driver->dumpWritesToStdout() ? $outputPath : null,
                    timeout: (int) config('database-backup.timeouts.backup', 3600),
                );

                if (! $result->successful()) {
                    throw new BackupException($result->failureMessage());
                }

                if (! is_file($outputPath)) {
                    throw new BackupException('The database utility did not produce a backup file.');
                }

                $fileSize = (int) filesize($outputPath);
                $checksum = hash_file('sha256', $outputPath) ?: null;

                if ($stagingPath !== null) {
                    $this->storage->putFromPath($backup->storage_path, $stagingPath);
                }
            } finally {
                if ($stagingPath !== null && is_file($stagingPath)) {
                    @unlink($stagingPath);
                }
            }

            $backup->forceFill([
                'status' => BackupStatus::Completed,
                'file_size' => $fileSize,
                'checksum_sha256' => $checksum,
                'completed_at' => CarbonImmutable::now(),
                'duration_seconds' => $this->durationSince($startedAt),
                'error_message' => null,
                'metadata' => array_merge((array) $backup->metadata, [
                    'engine' => $target->engine,
                    'engine_label' => $target->engineLabel(),
                    'database' => $target->database,
                    'checksum_algorithm' => 'sha256',
                ]),
            ])->save();

            $this->audit->record(
                action: $backup->backup_type === BackupType::Manual
                    ? DatabaseBackupAction::BackupCreated
                    : DatabaseBackupAction::BackupScheduled,
                backup: $backup,
                metadata: [
                    'filename' => $backup->filename,
                    'file_size' => $backup->file_size,
                    'backup_type' => $backup->backup_type->value,
                    'duration_seconds' => $backup->duration_seconds,
                ],
            );

            $this->notify(
                title: 'Database backup completed',
                message: "{$backup->backup_type->label()} backup of {$backup->database_name} completed successfully.",
                level: 'success',
                context: ['backup_uuid' => $backup->uuid],
            );

            return $backup->refresh();
        } catch (Throwable $exception) {
            $this->markFailed($backup, $startedAt, $exception);

            throw $exception instanceof BackupException
                ? $exception
                : new BackupException($exception->getMessage(), 0, $exception);
        } finally {
            $lock->release();
        }
    }

    /**
     * Persist an uploaded .sql file as a completed backup.
     */
    public function upload(UploadedFile $file, ?User $user = null): DatabaseBackup
    {
        $this->ensureEnabled();

        if (! $file->isValid()) {
            throw new BackupException('The uploaded SQL file could not be read.');
        }

        $originalName = mb_substr($file->getClientOriginalName(), 0, 255);

        if (! in_array(strtolower($file->getClientOriginalExtension()), (array) config('database-backup.allowed_extensions', ['sql']), true)) {
            throw new BackupException('Only .sql backup files may be uploaded.');
        }

        $maxBytes = (int) config('database-backup.max_upload_size', 51200) * 1024;

        if ($file->getSize() > $maxBytes) {
            throw new BackupException('The uploaded backup exceeds the maximum allowed size.');
        }

        $target = DatabaseTarget::fromConnection();
        $detectedEngine = $this->storage->detectEngine($file->getRealPath());

        $filename = $this->storage->generateFilename($target, BackupType::Uploaded);
        $relativePath = $this->storage->relativePath($filename);
        $storedPath = $this->storage->stagingPath($filename);
        $checksum = hash_file('sha256', $file->getRealPath()) ?: null;

        $source = @fopen($file->getRealPath(), 'rb');
        $destination = @fopen($storedPath, 'wb');

        if ($source === false || $destination === false) {
            if (is_resource($source)) {
                fclose($source);
            }

            @unlink($storedPath);

            throw new BackupException('The uploaded SQL file could not be read or stored.');
        }

        try {
            $copied = stream_copy_to_stream($source, $destination);
        } finally {
            fclose($source);
            fclose($destination);
        }

        if ($copied === false || ! is_file($storedPath)) {
            @unlink($storedPath);

            throw new BackupException('The uploaded SQL file could not be stored.');
        }

        try {
            $this->storage->putFromPath($relativePath, $storedPath);
        } finally {
            @unlink($storedPath);
        }

        $backup = DatabaseBackup::create([
            'filename' => $filename,
            'original_filename' => $originalName,
            'database_driver' => $detectedEngine ?? 'unknown',
            'database_name' => $target->database,
            'backup_type' => BackupType::Uploaded,
            'storage_disk' => $this->storage->diskName(),
            'storage_path' => $relativePath,
            'file_size' => (int) filesize($file->getRealPath()),
            'checksum_sha256' => $checksum,
            'status' => BackupStatus::Completed,
            'started_at' => CarbonImmutable::now(),
            'completed_at' => CarbonImmutable::now(),
            'duration_seconds' => 0,
            'created_by' => $user?->id,
            'metadata' => [
                'engine_label' => $target->engineLabel(),
                'detected_engine' => $detectedEngine,
                'source' => 'upload',
                'checksum_algorithm' => 'sha256',
            ],
        ]);

        $this->audit->record(
            action: DatabaseBackupAction::BackupUploaded,
            backup: $backup,
            user: $user,
            metadata: [
                'filename' => $filename,
                'original_filename' => $originalName,
                'file_size' => $backup->file_size,
                'detected_engine' => $detectedEngine,
            ],
        );

        return $backup;
    }

    /**
     * Delete a backup file and mark the record as deleted.
     *
     * Uploaded and manual backups are only ever removed explicitly; the
     * retention job calls this method for eligible scheduled backups.
     */
    public function delete(DatabaseBackup $backup, ?User $user = null, string $reason = 'manual'): void
    {
        if ($backup->isBusy()) {
            throw new BackupException('A backup that is still pending or running cannot be deleted.');
        }

        if ($backup->status === BackupStatus::Deleted) {
            return;
        }

        if ($this->storage->exists($backup->storage_path)) {
            $this->storage->delete($backup->storage_path);

            if ($this->storage->exists($backup->storage_path)) {
                throw new BackupException('The backup file could not be removed from storage.');
            }
        }

        $backup->forceFill([
            'status' => BackupStatus::Deleted,
            'metadata' => array_merge((array) $backup->metadata, [
                'deleted_at' => CarbonImmutable::now()->toIso8601String(),
                'deleted_reason' => $reason,
            ]),
        ])->save();

        $this->audit->record(
            action: DatabaseBackupAction::BackupDeleted,
            backup: $backup,
            user: $user,
            metadata: [
                'filename' => $backup->filename,
                'backup_type' => $backup->backup_type->value,
                'reason' => $reason,
            ],
        );
    }

    /**
     * The configured private disk, exposed for the download endpoint.
     */
    public function disk(): Filesystem
    {
        return $this->storage->disk();
    }

    public function storage(): DatabaseBackupStorageService
    {
        return $this->storage;
    }

    public function ensureEnabled(): void
    {
        if (! (bool) config('database-backup.enabled', true)) {
            throw new BackupException('Database backups are disabled in the application configuration.');
        }
    }

    /**
     * Decide where the native utility writes the dump.
     *
     * @return array{0: string, 1: string|null}
     */
    private function prepareOutput(DatabaseBackup $backup): array
    {
        $absolute = $this->storage->absolutePath($backup->storage_path);

        if ($absolute !== null) {
            $this->storage->ensureDirectoryExists();

            return [$absolute, null];
        }

        $staging = $this->storage->stagingPath($backup->filename);

        return [$staging, $staging];
    }

    private function markFailed(DatabaseBackup $backup, CarbonImmutable $startedAt, Throwable $exception): void
    {
        $backup->forceFill([
            'status' => BackupStatus::Failed,
            'completed_at' => CarbonImmutable::now(),
            'duration_seconds' => $this->durationSince($startedAt),
            'error_message' => $this->sanitize($exception->getMessage()),
        ])->save();

        $this->audit->record(
            action: DatabaseBackupAction::BackupFailed,
            backup: $backup,
            metadata: [
                'filename' => $backup->filename,
                'error' => $this->sanitize($exception->getMessage()),
            ],
        );

        $this->notify(
            title: 'Database backup failed',
            message: "The backup of {$backup->database_name} failed.",
            level: 'error',
            context: ['backup_uuid' => $backup->uuid, 'error' => $this->sanitize($exception->getMessage())],
        );
    }

    private function durationSince(CarbonImmutable $startedAt): int
    {
        return max(0, $startedAt->diffInSeconds(CarbonImmutable::now()));
    }

    /**
     * Remove server paths and truncate messages before they are persisted.
     */
    public function sanitize(string $message): string
    {
        $message = str_replace(
            [base_path().DIRECTORY_SEPARATOR, base_path(), storage_path()],
            ['', '[app]', '[storage]'],
            $message
        );

        return mb_substr(trim($message), 0, 2000);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function notify(string $title, string $message, string $level, array $context = []): void
    {
        $email = config('database-backup.notifications.email');

        if (! is_string($email) || $email === '') {
            return;
        }

        try {
            Notification::route('mail', $email)->notify(
                new DatabaseBackupNotification($title, $message, $level, $context)
            );
        } catch (Throwable) {
            // Notifications must never fail a backup or restore operation.
        }
    }
}
