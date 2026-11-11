<?php

namespace App\Jobs;

use App\Services\DatabaseBackupRetentionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CleanupDatabaseBackupsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue((string) config('database-backup.queue', 'database-backups'));
    }

    public function handle(DatabaseBackupRetentionService $retention): void
    {
        $summary = $retention->cleanup();

        Log::info('Database backup retention cleanup finished.', $summary);
    }
}
