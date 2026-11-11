<?php

namespace App\Console\Commands;

use App\Jobs\CreateDatabaseBackupJob;
use App\Services\DatabaseBackup\BackupException;
use App\Services\DatabaseBackupScheduleService;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('database:backup-schedule-run {--sync : Run due backups in the current process instead of queueing them}')]
#[Description('Dispatch every database backup schedule that is due.')]
class DatabaseBackupScheduleRunCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        DatabaseBackupScheduleService $schedules,
        DatabaseBackupService $backups,
    ): int {
        if (! (bool) config('database-backup.enabled', true)) {
            $this->warn('Database backups are disabled; no schedules were dispatched.');

            return self::SUCCESS;
        }

        $schedules->ensureDefaults();

        $due = $schedules->dueSchedules();

        if ($due->isEmpty()) {
            $this->info('No database backups are due.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($due as $schedule) {
            if (! $schedule->is_enabled) {
                continue;
            }

            try {
                $backup = $backups->createRecord($schedule->frequency->backupType());

                if ((bool) $this->option('sync')) {
                    $backups->run($backup);
                } else {
                    CreateDatabaseBackupJob::dispatch($backup->id);
                }

                $schedules->markExecuted($schedule);

                $this->info("Dispatched {$schedule->frequency->label()} backup {$backup->filename}.");
            } catch (BackupException $exception) {
                $failures++;

                $this->error("The {$schedule->frequency->label()} backup failed: ".$backups->sanitize($exception->getMessage()));
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
