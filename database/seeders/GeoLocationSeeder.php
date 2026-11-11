<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The Philippine geography the SPA's location pickers use.
 *
 * Rows come from `database/seeders/data/geo_ph.json.gz`, generated from the
 * PSGC API by `database/seeders/geo/build.php`: all 17 regions, 81
 * provinces, the Metro Manila district node for NCR's province-less cities,
 * every city/municipality, and every barangay. IDs follow the SPA's
 * `r-/p-/c-/b-` convention and codes are PSGC codes.
 *
 * The two independent cities PSGC gives no province (Isabela City and
 * Cotabato City) are absent; FIXED.md records why. Re-running the seeder
 * replaces the table, so it stays in step with the generated file.
 */
class GeoLocationSeeder extends Seeder
{
    public function run(): void
    {
        $file = database_path('seeders/data/geo_ph.json.gz');

        if (! is_file($file)) {
            throw new RuntimeException(
                'Missing seeders/data/geo_ph.json.gz. Build it with: php database/seeders/geo/build.php',
            );
        }

        $rows = json_decode((string) gzdecode((string) file_get_contents($file)), true);

        if (! is_array($rows) || $rows === []) {
            throw new RuntimeException('seeders/data/geo_ph.json.gz did not decode to any rows.');
        }

        // Reference data: start clean so removed or renamed PSGC rows do not
        // linger. player_profiles.barangay_id has no foreign key, so a saved
        // location that no longer exists simply reads as no home.
        DB::table('geo_locations')->delete();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('geo_locations')->insert($chunk);
        }
    }
}
