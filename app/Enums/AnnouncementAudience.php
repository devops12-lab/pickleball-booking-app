<?php

namespace App\Enums;

enum AnnouncementAudience: string
{
    case All = 'all';
    case Players = 'players';
    case Staff = 'staff';
    case Owners = 'owners';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Everyone',
            self::Players => 'Players',
            self::Staff => 'Facility Staff',
            self::Owners => 'Facility Owners',
        };
    }

    /**
     * Determine whether the audience includes the given role name.
     */
    public function includes(string $roleName): bool
    {
        $target = match ($this) {
            self::All => null,
            self::Players => 'player',
            self::Staff => 'staff',
            self::Owners => 'owner',
        };

        return $target === null || $target === $roleName;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
