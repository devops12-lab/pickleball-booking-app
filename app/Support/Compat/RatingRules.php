<?php

namespace App\Support\Compat;

use App\Models\RatingRule;

/**
 * The rating rules the Super Admin can edit. They drive new-player ratings,
 * provisional status and the K-factor used by the ELO calculation.
 */
class RatingRules
{
    /** @var array<string, int> */
    public const DEFAULTS = [
        'initial' => 1200,
        'provisional_matches' => 5,
        'k_provisional' => 32,
        'k_established' => 24,
    ];

    /**
     * @return array<string, int>
     */
    public static function current(): array
    {
        $row = RatingRule::query()->first();

        return $row === null ? self::DEFAULTS : [
            'initial' => (int) $row->initial,
            'provisional_matches' => (int) $row->provisional_matches,
            'k_provisional' => (int) $row->k_provisional,
            'k_established' => (int) $row->k_established,
        ];
    }

    /**
     * @param  array<string, int>  $rules
     * @return array<string, int>
     */
    public static function save(array $rules): array
    {
        $row = RatingRule::query()->firstOrCreate([], self::DEFAULTS);
        $row->fill($rules)->save();

        return self::current();
    }
}
