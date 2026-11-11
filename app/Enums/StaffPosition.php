<?php

namespace App\Enums;

enum StaffPosition: string
{
    case Manager = 'manager';
    case FrontDesk = 'front_desk';
    case CourtAttendant = 'court_attendant';
    case Scorer = 'scorer';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Manager',
            self::FrontDesk => 'Front Desk',
            self::CourtAttendant => 'Court Attendant',
            self::Scorer => 'Scorer',
        };
    }

    /**
     * Permission names granted to this position (whitelist semantics:
     * when a staff member has a position, only these permissions apply).
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return config("staff-positions.positions.{$this->value}.permissions", []);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
