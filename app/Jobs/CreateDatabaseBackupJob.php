<?php

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Models\DatabaseBackup;
use App\Services\DatabaseBackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CreateDatabaseBackupJob implements ShouldQueue
{
    use Queueable;

    /**
     * Backups are never retried automatically: a partial dump must not be
     * silently resumed, and the administrator can trigger a new backup.
     */
    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public int $backupId
    ) {
        $this->timeout = (int) config('database-backup.timeouts.backup', 3600) + 60;
        $this->onQueue((string) config('database-backup.queue', 'database-backups'));
    }

    public function handle(DatabaseBackupService $backups): void
    {
        $backup = DatabaseBackup::query()->find($this->backupId);

        if (! $backup instanceof DatabaseBackup) {
            return;
        }

        $backups->run($backup);
    }

    /**
     * Defensive fallback when the worker is killed before the service can
     * record the failure.
     */
    public function failed(?Throwable $exception): void
    {
        DatabaseBackup::query()
            ->whereKey($this->backupId)
            ->whereIn('status', [BackupStatus::Pending->value, BackupStatus::Processing->value])
            ->update([
                'status' => BackupStatus::Failed->value,
                'error_message' => mb_substr((string) $exception?->getMessage(), 0, 2000),
                'completed_at' => now(),
            ]);
    }
}
