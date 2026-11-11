<?php

namespace App\Services;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Enums\DatabaseBackupAction;
use App\Enums\RestoreStatus;
use App\Models\DatabaseBackup;
use App\Models\DatabaseBackupSchedule;
use App\Models\DatabaseRestoreLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as ScheduleCollection;
use Throwable;

/**
 * Applies the configurable retention policy to scheduled backups.
 *
 * Manual, uploaded, pending, processing and currently restoring backups are
 * never removed automatically.
 */
class DatabaseBackupRetentionService
{
    public function __construct(
        protected DatabaseBackupService $backups,
        protected DatabaseBackupScheduleService $schedules,
        protected DatabaseBackupAuditService $audit,
    ) {}

    /**
     * @return array{deleted: int, kept: int, skipped: int, errors: list<string>}
     */
    public function cleanup(): array
    {
        $summary = [
            'deleted' => 0,
            'kept' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $schedules = $this->schedules->ensureDefaults();

        foreach ([BackupType::Daily, BackupType::Weekly, BackupType::Monthly] as $type) {
            $retention = $this->retentionFor($type, $schedules);

            if ($retention <= 0) {
                continue;
            }

            /** @var Collection<int, DatabaseBackup> $backups */
            $backups = DatabaseBackup::query()
                ->where('backup_type', $type->value)
                ->where('status', BackupStatus::Completed->value)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();

            $summary['kept'] += min($retention, $backups->count());

            foreach ($backups->slice($retention) as $backup) {
                if ($this->isProtected($backup)) {
                    $summary['skipped']++;

                    continue;
                }

                try {
                    $this->backups->delete($backup, reason: 'retention');
                    $summary['deleted']++;
                } catch (Throwable $exception) {
                    $summary['errors'][] = "Backup #{$backup->id}: ".$this->backups->sanitize($exception->getMessage());
                }
            }
        }

        $this->audit->record(
            action: DatabaseBackupAction::RetentionCleanup,
            metadata: [
                'deleted' => $summary['deleted'],
                'kept' => $summary['kept'],
                'skipped' => $summary['skipped'],
                'errors' => count($summary['errors']),
            ],
        );

        return $summary;
    }

    /**
     * Number of scheduled backups of a type to keep.
     *
     * @param  ScheduleCollection<int, DatabaseBackupSchedule>  $schedules
     */
    private function retentionFor(BackupType $type, ScheduleCollection $schedules): int
    {
        $schedule = $schedules->first(
            fn ($schedule): bool => $schedule->frequency->backupType() === $type
        );

        if ($schedule !== null) {
            return (int) $schedule->retention_count;
        }

        return (int) config("database-backup.schedules.{$type->value}.retention", 0);
    }

    /**
     * Never delete a backup that is being inspected or restored.
     */
    private function isProtected(DatabaseBackup $backup): bool
    {
        if (! $backup->backup_type->isRetentionEligible()) {
            return true;
        }

        if ($backup->status !== BackupStatus::Completed) {
            return true;
        }

        return DatabaseRestoreLog::query()
            ->where('database_backup_id', $backup->id)
            ->whereIn('restore_status', [
                RestoreStatus::Pending->value,
                RestoreStatus::Processing->value,
            ])
            ->exists();
    }
}
