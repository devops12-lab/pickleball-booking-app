<?php

namespace App\Enums;

enum BackupStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Deleted => 'Deleted',
        };
    }

    /**
     * Determine whether the backup can be downloaded.
     */
    public function isDownloadable(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Determine whether the backup can be restored.
     */
    public function isRestorable(): bool
    {
        return $this === self::Completed;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
