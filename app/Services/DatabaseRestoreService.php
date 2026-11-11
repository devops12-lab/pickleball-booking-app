<?php

namespace App\Services;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Enums\DatabaseBackupAction;
use App\Enums\RestoreStatus;
use App\Jobs\RestoreDatabaseBackupJob;
use App\Models\DatabaseBackup;
use App\Models\DatabaseRestoreLog;
use App\Models\User;
use App\Notifications\DatabaseBackupNotification;
use App\Services\DatabaseBackup\BackupDriverFactory;
use App\Services\DatabaseBackup\BackupException;
use App\Services\DatabaseBackup\DatabaseTarget;
use App\Services\DatabaseBackup\ProcessRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Validates and performs database restores.
 *
 * A restore is only queued after every guard passes: the backup must be
 * completed, its engine must match the active connection, its checksum must
 * still match, and the administrator must have typed the target database name.
 */
class DatabaseRestoreService
{
    public function __construct(
        protected DatabaseBackupService $backups,
        protected DatabaseBackupStorageService $storage,
        protected BackupDriverFactory $drivers,
        protected ProcessRunner $processRunner,
        protected DatabaseBackupAuditService $audit,
    ) {}

    /**
     * Validate a restore request and queue the restore job.
     *
     * @throws BackupException
     */
    public function initiate(
        DatabaseBackup $backup,
        User $user,
        string $confirmation,
        bool $acknowledge,
    ): DatabaseRestoreLog {
        $this->backups->ensureEnabled();

        if (! (bool) config('database-backup.restore.enabled', true)) {
            throw new BackupException('Database restores are disabled in the application configuration.');
        }

        if ($backup->status !== BackupStatus::Completed) {
            throw new BackupException('Only completed backups can be restored.');
        }

        $target = DatabaseTarget::fromConnection();

        if ($backup->database_driver !== $target->engine) {
            throw new BackupException(
                "This backup was created for the [{$backup->database_driver}] engine but the active database uses [{$target->engine}]. Restores require a matching engine."
            );
        }

        if (! $acknowledge) {
            throw new BackupException('The restore acknowledgement must be accepted.');
        }

        if (! hash_equals($target->database, $confirmation)) {
            throw new BackupException('The typed database name does not match the target database.');
        }

        if (! $this->storage->exists($backup->storage_path)) {
            throw new BackupException('The backup file is missing from storage.');
        }

        $size = $this->storage->size($backup->storage_path);
        $maxBytes = (int) config('database-backup.max_upload_size', 51200) * 1024;

        if ($size > $maxBytes) {
            throw new BackupException('The backup file exceeds the maximum size allowed for restores.');
        }

        if ($backup->checksum_sha256 !== null) {
            $actual = $this->storage->checksum($backup->storage_path);

            if ($actual === null || ! hash_equals($backup->checksum_sha256, $actual)) {
                throw new BackupException('The backup checksum does not match the recorded value. The file may have been modified.');
            }
        }

        if (! $target->isBackupable()) {
            throw new BackupException(
                'In-memory SQLite databases cannot be restored. Configure a file based SQLite database or use MySQL / PostgreSQL.'
            );
        }

        $busy = DatabaseRestoreLog::query()
            ->whereIn('restore_status', [RestoreStatus::Pending->value, RestoreStatus::Processing->value])
            ->exists();

        if ($busy) {
            throw new BackupException('Another database restore is already pending or running.');
        }

        $log = DatabaseRestoreLog::create([
            'database_backup_id' => $backup->id,
            'original_filename' => $backup->original_filename ?? $backup->filename,
            'database_driver' => $target->engine,
            'database_name' => $target->database,
            'restore_status' => RestoreStatus::Pending,
            'executed_by' => $user->id,
            'metadata' => [
                'backup_uuid' => $backup->uuid,
                'confirmation_verified' => true,
                'checksum_verified' => $backup->checksum_sha256 !== null,
                'requested_at' => CarbonImmutable::now()->toIso8601String(),
            ],
        ]);

        $this->audit->record(
            action: DatabaseBackupAction::RestoreInitiated,
            backup: $backup,
            user: $user,
            metadata: [
                'restore_uuid' => $log->uuid,
                'database' => $target->database,
                'engine' => $target->engine,
            ],
        );

        RestoreDatabaseBackupJob::dispatch($log->id)->onQueue((string) config('database-backup.queue', 'database-backups'));

        return $log;
    }

    /**
     * Execute a queued restore.
     */
    public function run(DatabaseRestoreLog $log): DatabaseRestoreLog
    {
        $log->refresh();

        if ($log->restore_status === RestoreStatus::Completed) {
            return $log;
        }

        if ($log->restore_status !== RestoreStatus::Pending) {
            throw new BackupException('Only pending restores can be executed.');
        }

        $lock = Cache::lock(
            'database-backup:restore',
            (int) config('database-backup.locks.restore_ttl', 7200)
        );

        if (! $lock->get()) {
            throw new BackupException('Another database restore is already running.');
        }

        $startedAt = CarbonImmutable::now();
        $backup = $log->backup;
        $temporaryPath = null;
        $maintenanceEnabled = false;

        try {
            if (! $backup instanceof DatabaseBackup) {
                throw new BackupException('The backup referenced by this restore no longer exists.');
            }

            $target = DatabaseTarget::fromConnection();

            if ($log->database_driver !== $target->engine) {
                throw new BackupException(
                    "The active database engine [{$target->engine}] no longer matches the backup engine [{$log->database_driver}]."
                );
            }

            $driver = $this->drivers->forDriver($target->engine);
            $driver->verify();

            $log->forceFill([
                'restore_status' => RestoreStatus::Processing,
                'started_at' => $startedAt,
                'error_message' => null,
            ])->save();

            if ((bool) config('database-backup.restore.safety_backup', true)) {
                $this->takeSafetyBackup($log);
            }

            if ((bool) config('database-backup.restore.maintenance_mode', false)) {
                Artisan::call('down', ['--render' => 'errors::503']);
                $maintenanceEnabled = true;
            }

            $copy = $this->storage->localCopy($backup->storage_path, $backup->filename);
            $temporaryPath = $copy['cleanup'] ? $copy['path'] : null;

            $result = $this->processRunner->run(
                command: $driver->restoreCommand($target),
                environment: $driver->environment($target),
                stdinPath: $copy['path'],
                timeout: (int) config('database-backup.timeouts.restore', 3600),
            );

            if (! $result->successful()) {
                throw new BackupException($result->failureMessage());
            }

            $log->forceFill([
                'restore_status' => RestoreStatus::Completed,
                'completed_at' => CarbonImmutable::now(),
                'duration_seconds' => max(0, $startedAt->diffInSeconds(CarbonImmutable::now())),
                'error_message' => null,
                'metadata' => array_merge((array) $log->metadata, [
                    'checksum_verified' => $backup->checksum_sha256 !== null,
                    'database' => $target->database,
                    'engine' => $target->engine,
                ]),
            ])->save();

            $this->audit->record(
                action: DatabaseBackupAction::RestoreCompleted,
                backup: $backup,
                metadata: [
                    'restore_uuid' => $log->uuid,
                    'duration_seconds' => $log->duration_seconds,
                ],
            );

            $this->notify(
                title: 'Database restore completed',
                message: "The database {$log->database_name} was restored successfully.",
                level: 'success',
                context: ['restore_uuid' => $log->uuid],
            );

            return $log->refresh();
        } catch (Throwable $exception) {
            $log->forceFill([
                'restore_status' => RestoreStatus::Failed,
                'completed_at' => CarbonImmutable::now(),
                'duration_seconds' => max(0, $startedAt->diffInSeconds(CarbonImmutable::now())),
                'error_message' => $this->backups->sanitize($exception->getMessage()),
            ])->save();

            $this->audit->record(
                action: DatabaseBackupAction::RestoreFailed,
                backup: $backup instanceof DatabaseBackup ? $backup : null,
                metadata: [
                    'restore_uuid' => $log->uuid,
                    'error' => $this->backups->sanitize($exception->getMessage()),
                ],
            );

            $this->notify(
                title: 'Database restore failed',
                message: "The restore of {$log->database_name} failed.",
                level: 'error',
                context: ['restore_uuid' => $log->uuid, 'error' => $this->backups->sanitize($exception->getMessage())],
            );

            throw $exception instanceof BackupException
                ? $exception
                : new BackupException($exception->getMessage(), 0, $exception);
        } finally {
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }

            if ($maintenanceEnabled) {
                Artisan::call('up');
            }

            $lock->release();
        }
    }

    /**
     * The most recent restore, used by the monitoring endpoint.
     */
    public function latest(): ?DatabaseRestoreLog
    {
        return DatabaseRestoreLog::query()->latest('id')->first();
    }

    /**
     * Create and execute a manual backup before overwriting the database.
     */
    private function takeSafetyBackup(DatabaseRestoreLog $log): void
    {
        $safety = null;

        try {
            $safety = $this->backups->createRecord(
                type: BackupType::Manual,
                userId: $log->executed_by,
                metadata: [
                    'reason' => 'pre-restore safety backup',
                    'restore_uuid' => $log->uuid,
                ],
            );

            $this->backups->run($safety);

            $log->forceFill([
                'metadata' => array_merge((array) $log->metadata, [
                    'safety_backup_id' => $safety->id,
                    'safety_backup_uuid' => $safety->uuid,
                ]),
            ])->save();
        } catch (Throwable $exception) {
            $log->forceFill([
                'metadata' => array_merge((array) $log->metadata, [
                    'safety_backup_error' => $this->backups->sanitize($exception->getMessage()),
                ]),
            ])->save();

            if ((bool) config('database-backup.restore.require_safety_backup', false)) {
                throw new BackupException(
                    'The safety backup required before restoring failed: '.$this->backups->sanitize($exception->getMessage()),
                    0,
                    $exception
                );
            }
        }
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
            // Notifications must never fail a restore.
        }
    }
}
