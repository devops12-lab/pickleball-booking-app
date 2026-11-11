<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupRetentionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('database:backup-cleanup')]
#[Description('Delete scheduled database backups that exceed the retention policy.')]
class DatabaseBackupCleanupCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DatabaseBackupRetentionService $retention): int
    {
        $summary = $retention->cleanup();

        $this->info(sprintf(
            'Retention cleanup finished: %d deleted, %d kept, %d skipped.',
            $summary['deleted'],
            $summary['kept'],
            $summary['skipped'],
        ));

        foreach ($summary['errors'] as $error) {
            $this->error($error);
        }

        return $summary['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
