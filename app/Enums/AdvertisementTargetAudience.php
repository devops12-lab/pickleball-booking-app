<?php

namespace App\Enums;

enum AdvertisementTargetAudience: string
{
    case All = 'all';
    case Players = 'players';
    case Owners = 'owners';
    case Staff = 'staff';
    case Organizers = 'organizers';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All Users',
            self::Players => 'Players',
            self::Owners => 'Facility Owners',
            self::Staff => 'Staff',
            self::Organizers => 'Organizers',
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
