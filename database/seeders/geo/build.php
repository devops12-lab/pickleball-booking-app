<?php

/**
 * Regenerates database/seeders/data/geo_ph.json.gz from the PSGC API.
 *
 *     php database/seeders/geo/build.php
 *
 * The four PSGC payloads are downloaded into a temporary folder and removed
 * again, so nothing but the generated .gz lives in the repository. Commit that
 * file; the seeder loads it.
 *
 * IDs follow the SPA's convention: r-<code>, p-<code>, c-<code>, b-<code>.
 *
 * Two notes:
 * - NCR has no provinces in PSGC; its 17 cities are listed directly under the
 *   region. The pickers' chain has a province step, so those cities go under a
 *   single "Metro Manila" district node (p-137400000), which the SPA's mocks
 *   already use.
 * - Isabela City and Cotabato City are independent cities with no province in
 *   PSGC. They are left out rather than filed under a province they do not
 *   belong to. FIXED.md records this.
 */

$target = dirname(__DIR__).'/data/geo_ph.json.gz';
$tmp = sys_get_temp_dir().'/courtflex-psgc-'.getmypid();

$sources = [
    'regions.json' => 'https://psgc.gitlab.io/api/regions/',
    'provinces.json' => 'https://psgc.gitlab.io/api/provinces/',
    'cities.json' => 'https://psgc.gitlab.io/api/cities-municipalities/',
    'barangays.json' => 'https://psgc.gitlab.io/api/barangays/',
];

$fetch = function (string $url): string {
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 180,
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

        if (is_string($body) && $status === 200 && $body !== '') {
            return $body;
        }
    }

    // This PHP build has no CA bundle for ext-curl, so fall back to the curl
    // binary (and then to the stream wrapper) before giving up.
    $body = shell_exec('curl -fsSL --max-time 180 '.escapeshellarg($url));

    if (is_string($body) && $body !== '') {
        return $body;
    }

    $body = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 180],
    ]));

    if (! is_string($body) || $body === '') {
        throw new RuntimeException("Could not download {$url}");
    }

    return $body;
};

if (! is_dir($tmp) && ! mkdir($tmp, 0777, true) && ! is_dir($tmp)) {
    throw new RuntimeException("Could not create {$tmp}");
}

foreach ($sources as $name => $url) {
    file_put_contents($tmp.'/'.$name, $fetch($url));
}

$decode = fn (string $name): array => json_decode((string) file_get_contents($tmp.'/'.$name), true) ?: [];

$regions = $decode('regions.json');
$provinces = $decode('provinces.json');
$cities = $decode('cities.json');
$barangays = $decode('barangays.json');

if ($regions === [] || $provinces === [] || $cities === [] || $barangays === []) {
    throw new RuntimeException('A PSGC payload came back empty; nothing was written.');
}

$NCR = '130000000';
$rows = [[
    'id' => 'ph',
    'code' => 'PH',
    'name' => 'Philippines',
    'parent_id' => null,
    'level' => 'country',
    'lat' => null,
    'lng' => null,
]];
$regionCodes = [];

foreach ($regions as $region) {
    $code = $region['code'];
    $regionCodes[$code] = true;

    $name = (string) $region['name'];
    $label = (string) ($region['regionName'] ?? '');

    if ($label !== '' && $label !== $name && ! str_contains($name, $label)) {
        $name .= " ({$label})";
    }

    $rows[] = [
        'id' => 'r-'.$code,
        'code' => (string) $code,
        'name' => $name,
        'parent_id' => 'ph',
        'level' => 'region',
        'lat' => null,
        'lng' => null,
    ];
}

/** @var array<string, array<string, mixed>> $provincesIn */
$provincesIn = [];

foreach ($provinces as $province) {
    if (! isset($regionCodes[$province['regionCode'] ?? ''])) {
        continue;
    }

    $provincesIn[$province['code']] = $province;

    $rows[] = [
        'id' => 'p-'.$province['code'],
        'code' => (string) $province['code'],
        'name' => (string) $province['name'],
        'parent_id' => 'r-'.$province['regionCode'],
        'level' => 'province',
        'lat' => null,
        'lng' => null,
    ];
}

// NCR's cities sit directly under the region; give them the district node the
// mocks use so the province step is never empty.
$ncrDistrict = null;

if (isset($regionCodes[$NCR]) && ! array_filter($provincesIn, fn ($p): bool => ($p['regionCode'] ?? '') === $NCR)) {
    $ncrDistrict = 'p-137400000';

    $rows[] = [
        'id' => $ncrDistrict,
        'code' => '137400000',
        'name' => 'Metro Manila',
        'parent_id' => 'r-'.$NCR,
        'level' => 'province',
        'lat' => null,
        'lng' => null,
    ];
}

/** @var array<string, array<string, mixed>> $citiesIn */
$citiesIn = [];
$skipped = [];

foreach ($cities as $city) {
    if (! isset($regionCodes[$city['regionCode'] ?? ''])) {
        continue;
    }

    $parent = null;

    if (isset($provincesIn[$city['provinceCode'] ?? ''])) {
        $parent = 'p-'.$city['provinceCode'];
    } elseif ($city['regionCode'] === $NCR && $ncrDistrict !== null) {
        $parent = $ncrDistrict;
    }

    if ($parent === null) {
        $skipped[] = ['code' => $city['code'], 'name' => $city['name']];

        continue;
    }

    $citiesIn[$city['code']] = $city;

    $rows[] = [
        'id' => 'c-'.$city['code'],
        'code' => (string) $city['code'],
        'name' => (string) $city['name'],
        'parent_id' => $parent,
        'level' => 'city',
        'lat' => null,
        'lng' => null,
    ];
}

foreach ($barangays as $barangay) {
    if (! isset($regionCodes[$barangay['regionCode'] ?? ''])) {
        continue;
    }

    $cityCode = $barangay['cityCode'] ?? null;

    if (! $cityCode) {
        $cityCode = $barangay['municipalityCode'] ?? null;
    }

    if (! $cityCode || ! isset($citiesIn[$cityCode])) {
        continue;
    }

    $rows[] = [
        'id' => 'b-'.$barangay['code'],
        'code' => (string) $barangay['code'],
        'name' => (string) $barangay['name'],
        'parent_id' => 'c-'.$cityCode,
        'level' => 'barangay',
        'lat' => null,
        'lng' => null,
    ];
}

if (! is_dir(dirname($target))) {
    mkdir(dirname($target), 0777, true);
}

file_put_contents($target, gzencode(json_encode($rows, JSON_UNESCAPED_UNICODE), 9));

foreach (array_keys($sources) as $name) {
    @unlink($tmp.'/'.$name);
}

@rmdir($tmp);

$counts = array_count_values(array_column($rows, 'level'));

echo 'wrote ', $target, "\n",
    'regions=', $counts['region'] ?? 0,
    ' provinces=', $counts['province'] ?? 0,
    ' cities=', $counts['city'] ?? 0,
    ' barangays=', $counts['barangay'] ?? 0,
    ' rows=', count($rows),
    ' skipped=', count($skipped),
    ' gz_bytes=', filesize($target), "\n";

foreach ($skipped as $city) {
    echo '  skipped ', $city['code'], ' ', $city['name'], "\n";
}
