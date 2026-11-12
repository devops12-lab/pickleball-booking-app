<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One node of the Philippines geography: country, region, province, city or
 * barangay. Rows are reference data (GeoLocationSeeder), identified by the
 * same string IDs the SPA's location pickers use.
 */
class GeoLocation extends Model
{
    protected $table = 'geo_locations';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'code', 'name', 'parent_id', 'level', 'lat', 'lng'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * The chain from barangay up to country, or null when the barangay is unknown.
     *
     * @return array{country: GeoLocation, region: GeoLocation, province: GeoLocation, city: GeoLocation, barangay: GeoLocation}|null
     */
    public static function chain(?string $barangayId): ?array
    {
        if ($barangayId === null || $barangayId === '') {
            return null;
        }

        $barangay = self::query()->find($barangayId);

        if ($barangay === null || $barangay->level !== 'barangay') {
            return null;
        }

        $city = $barangay->parent_id === null ? null : self::query()->find($barangay->parent_id);
        $province = $city?->parent_id === null || $city === null ? null : self::query()->find($city->parent_id);
        $region = $province?->parent_id === null || $province === null ? null : self::query()->find($province->parent_id);
        $country = $region?->parent_id === null || $region === null ? null : self::query()->find($region->parent_id);

        if ($city === null || $province === null || $region === null || $country === null) {
            return null;
        }

        return compact('country', 'region', 'province', 'city', 'barangay');
    }

    /**
     * Batch-resolve multiple barangay chains in at most 2 queries instead of 5×N.
     *
     * First query loads the barangays and collects parent IDs up the tree.
     * Second query loads every ancestor. Chains are then assembled from the
     * keyed collection with no further database hits.
     *
     * @param  list<string|null>  $barangayIds
     * @return array<string, array{country: GeoLocation, region: GeoLocation, province: GeoLocation, city: GeoLocation, barangay: GeoLocation}|null>
     */
    public static function chains(array $barangayIds): array
    {
        $uniqueIds = array_values(array_filter(array_unique($barangayIds)));

        if ($uniqueIds === []) {
            return [];
        }

        // 1. Load barangays and walk parent_id pointers to collect ancestor IDs.
        $barangays = self::query()->whereIn('id', $uniqueIds)->get()->keyBy('id');
        $ancestorIds = [];

        foreach ($barangays as $node) {
            if ($node->parent_id !== null) {
                $ancestorIds[] = $node->parent_id;
            }
        }

        // 2. Load all potential ancestors (cities) and continue walking up.
        //    We iterate level-by-level (city→province→region→country) collecting IDs.
        $allNodes = clone $barangays;
        for ($level = 0; $level < 4 && $ancestorIds !== []; $level++) {
            $batch = self::query()->whereIn('id', array_unique($ancestorIds))->get()->keyBy('id');
            $allNodes = $allNodes->merge($batch);

            $ancestorIds = [];
            foreach ($batch as $node) {
                if ($node->parent_id !== null && ! $allNodes->has($node->parent_id)) {
                    $ancestorIds[] = $node->parent_id;
                }
            }
        }

        // 3. Assemble chains from the keyed collection — zero extra queries.
        $result = [];
        foreach ($uniqueIds as $id) {
            $barangay = $allNodes->get($id);
            if ($barangay === null || $barangay->level !== 'barangay') {
                $result[$id] = null;

                continue;
            }

            $city = $barangay->parent_id ? $allNodes->get($barangay->parent_id) : null;
            $province = $city?->parent_id ? $allNodes->get($city->parent_id) : null;
            $region = $province?->parent_id ? $allNodes->get($province->parent_id) : null;
            $country = $region?->parent_id ? $allNodes->get($region->parent_id) : null;

            if ($city === null || $province === null || $region === null || $country === null) {
                $result[$id] = null;

                continue;
            }

            $result[$id] = compact('country', 'region', 'province', 'city', 'barangay');
        }

        return $result;
    }

    /**
     * The `{id, code, name}` options the pickers render, sorted by name.
     *
     * @return list<array{id: string, code: string, name: string}>
     */
    public static function options(?string $parentId, string $level): array
    {
        return self::query()
            ->where('level', $level)
            ->when($parentId !== null, fn ($query) => $query->where('parent_id', $parentId))
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (GeoLocation $node): array => [
                'id' => (string) $node->id,
                'code' => (string) $node->code,
                'name' => (string) $node->name,
            ])
            ->all();
    }
}
