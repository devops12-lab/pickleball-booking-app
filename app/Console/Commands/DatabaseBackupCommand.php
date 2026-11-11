<?php

namespace App\Console\Commands;

use App\Enums\BackupType;
use App\Jobs\CreateDatabaseBackupJob;
use App\Services\DatabaseBackup\BackupException;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('database:backup {--type=manual : Backup type to create (manual, daily, weekly, monthly)} {--sync : Run the backup in the current process instead of queueing it}')]
#[Description('Create a MySQL, PostgreSQL or SQLite database backup.')]
class DatabaseBackupCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DatabaseBackupService $backups): int
    {
        $type = BackupType::tryFrom((string) $this->option('type'));

        if ($type === null || $type === BackupType::Uploaded) {
            $this->error('The --type option must be one of: manual, daily, weekly, monthly.');

            return self::FAILURE;
        }

        try {
            $backup = $backups->createRecord($type);

            if ((bool) $this->option('sync')) {
                $backups->run($backup);

                $backup->refresh();

                $this->info("Backup {$backup->filename} completed ({$backup->file_size} bytes).");

                return self::SUCCESS;
            }

            CreateDatabaseBackupJob::dispatch($backup->id);
        } catch (BackupException $exception) {
            $this->error($backups->sanitize($exception->getMessage()));

            return self::FAILURE;
        }

        $this->info("Backup {$backup->filename} queued as operation {$backup->uuid}.");

        return self::SUCCESS;
    }
}
