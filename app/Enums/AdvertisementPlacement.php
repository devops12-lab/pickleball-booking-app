<?php

namespace App\Enums;

enum AdvertisementPlacement: string
{
    case Dashboard = 'dashboard';
    case Discovery = 'discovery';
    case Booking = 'booking';
    case Global = 'global';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard',
            self::Discovery => 'Discovery',
            self::Booking => 'Booking',
            self::Global => 'Global',
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
