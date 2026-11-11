<?php

namespace App\Enums;

enum BackupFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
        };
    }

    public function backupType(): BackupType
    {
        return match ($this) {
            self::Daily => BackupType::Daily,
            self::Weekly => BackupType::Weekly,
            self::Monthly => BackupType::Monthly,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
