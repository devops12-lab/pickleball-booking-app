<?php

namespace App\Enums;

enum AnnouncementType: string
{
    case General = 'general';
    case Maintenance = 'maintenance';
    case Promotion = 'promotion';
    case Event = 'event';
    case Alert = 'alert';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Maintenance => 'Maintenance',
            self::Promotion => 'Promotion',
            self::Event => 'Event',
            self::Alert => 'Alert',
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
