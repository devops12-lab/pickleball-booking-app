<?php

namespace App\Jobs;

use App\Enums\RestoreStatus;
use App\Models\DatabaseRestoreLog;
use App\Services\DatabaseRestoreService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RestoreDatabaseBackupJob implements ShouldQueue
{
    use Queueable;

    /**
     * Restores are never retried automatically: a failed restore needs the
     * administrator to inspect the database before trying again.
     */
    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public int $restoreLogId
    ) {
        $this->timeout = (int) config('database-backup.timeouts.restore', 3600) + 60;
        $this->onQueue((string) config('database-backup.queue', 'database-backups'));
    }

    public function handle(DatabaseRestoreService $restores): void
    {
        $log = DatabaseRestoreLog::query()->find($this->restoreLogId);

        if (! $log instanceof DatabaseRestoreLog) {
            return;
        }

        $restores->run($log);
    }

    public function failed(?Throwable $exception): void
    {
        DatabaseRestoreLog::query()
            ->whereKey($this->restoreLogId)
            ->whereIn('restore_status', [RestoreStatus::Pending->value, RestoreStatus::Processing->value])
            ->update([
                'restore_status' => RestoreStatus::Failed->value,
                'error_message' => mb_substr((string) $exception?->getMessage(), 0, 2000),
                'completed_at' => now(),
            ]);
    }
}
