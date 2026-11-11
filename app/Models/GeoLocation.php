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
     * Keyed lookups: the table holds the whole country (~44k rows), so loading
     * it per request just to resolve five nodes would be wasteful.
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
