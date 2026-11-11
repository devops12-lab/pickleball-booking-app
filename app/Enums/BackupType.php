<?php

namespace App\Enums;

enum BackupType: string
{
    case Manual = 'manual';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Uploaded = 'uploaded';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Uploaded => 'Uploaded',
        };
    }

    /**
     * Determine whether retention cleanup may remove this type.
     */
    public function isRetentionEligible(): bool
    {
        return in_array($this, [self::Daily, self::Weekly, self::Monthly], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
