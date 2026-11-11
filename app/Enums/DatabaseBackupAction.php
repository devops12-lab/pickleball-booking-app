<?php

namespace App\Enums;

enum DatabaseBackupAction: string
{
    case BackupCreated = 'backup.created';
    case BackupScheduled = 'backup.scheduled';
    case BackupDownloaded = 'backup.downloaded';
    case BackupUploaded = 'backup.uploaded';
    case BackupDeleted = 'backup.deleted';
    case BackupFailed = 'backup.failed';
    case RestoreInitiated = 'restore.initiated';
    case RestoreCompleted = 'restore.completed';
    case RestoreFailed = 'restore.failed';
    case ScheduleUpdated = 'schedule.updated';
    case RetentionCleanup = 'backup.retention_cleanup';

    public function label(): string
    {
        return match ($this) {
            self::BackupCreated => 'Manual backup created',
            self::BackupScheduled => 'Scheduled backup executed',
            self::BackupDownloaded => 'Backup downloaded',
            self::BackupUploaded => 'Backup uploaded',
            self::BackupDeleted => 'Backup deleted',
            self::BackupFailed => 'Backup failed',
            self::RestoreInitiated => 'Database restore initiated',
            self::RestoreCompleted => 'Database restore completed',
            self::RestoreFailed => 'Database restore failed',
            self::ScheduleUpdated => 'Backup schedule modified',
            self::RetentionCleanup => 'Backup retention cleanup',
        };
    }
}
