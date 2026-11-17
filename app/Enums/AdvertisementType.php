<?php

namespace App\Enums;

enum AdvertisementType: string
{
    case Banner = 'banner';
    case Popup = 'popup';
    case Sidebar = 'sidebar';
    case Inline = 'inline';

    public function label(): string
    {
        return match ($this) {
            self::Banner => 'Banner',
            self::Popup => 'Pop-up',
            self::Sidebar => 'Sidebar',
            self::Inline => 'Inline',
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
