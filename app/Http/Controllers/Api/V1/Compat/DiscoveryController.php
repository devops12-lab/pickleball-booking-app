<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Court;
use App\Models\CourtBlockedSchedule;
use App\Models\Tenant;
use App\Models\VenueProfile;
use App\Services\BookingAvailabilityService;
use App\Support\Compat\Contract;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything a visitor may see before signing in: venue search, one court's
 * page, and that court's slot-by-slot availability (§9.4).
 *
 * A venue only appears once it is active, not read-only and has a map pin;
 * those are exactly the venues that can be booked.
 */
class DiscoveryController extends Controller
{
    use ApiResponse;

    /** The SPA's filter chips (packages/mocks/src/player-db.ts AMENITIES). */
    public const AMENITIES = [
        'Parking',
        'Lights',
        'Restrooms',
        'Covered court',
        'Air-conditioned',
        'Paddle rental',
        'Showers',
        'Lockers',
        'Food nearby',
        'Pro shop',
    ];

    public function __construct(private readonly BookingAvailabilityService $availability) {}

    public function amenities(): JsonResponse
    {
        return $this->successResponse(data: self::AMENITIES);
    }

    /**
     * Venue search. Every filter and the sort are applied here, not in the browser.
     */
    public function courts(Request $request): JsonResponse
    {
        $sort = (string) $request->query('sort', 'recommended');

        if (! in_array($sort, ['recommended', 'availability', 'price_asc', 'price_desc'], true)) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: ['sort' => ['Sort by recommended, availability or price.']],
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $query = trim((string) $request->query('q', ''));
        $io = (string) $request->query('indoor_outdoor', '');
        $surface = (string) $request->query('surface', '');
        $maxPrice = (float) $request->query('max_price', 0);
        $radius = (float) $request->query('radius_km', 0);
        $availableToday = $request->query('available_today') === '1';

        $wanted = array_values(array_filter(
            array_map('trim', explode(',', (string) $request->query('amenities', ''))),
            fn (string $value): bool => $value !== '',
        ));

        $latRaw = $request->query('lat');
        $lngRaw = $request->query('lng');
        $hasPoint = is_numeric($latRaw) && is_numeric($lngRaw);
        $lat = $hasPoint ? (float) $latRaw : 0.0;
        $lng = $hasPoint ? (float) $lngRaw : 0.0;

        $tenants = Cache::remember('discovery:tenants', 60, function () {
            return Tenant::query()
                ->with(['venueProfile', 'subscription', 'courts.operatingHours', 'courts.priceRules'])
                ->withCount(['openPlaySessions as upcoming_session_count' => function ($query): void {
                    $query->where('starts_at', '>=', now())->where('status', '!=', 'cancelled');
                }])
                ->get();
        });

        $results = [];

        foreach ($tenants as $tenant) {
            $profile = $tenant->venueProfile;
            $club = Contract::club($tenant);

            if ($profile === null || $club['status'] !== 'active' || $club['read_only']) {
                continue;
            }

            if ($profile->latitude === null || $profile->longitude === null) {
                continue;
            }

            $amenities = array_values((array) ($profile->amenities ?? []));

            if (array_diff($wanted, $amenities) !== []) {
                continue;
            }

            if ($query !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    $tenant->name,
                    $club['city'],
                    $club['province'],
                    $club['address'],
                ]));

                if (! str_contains($haystack, mb_strtolower($query))) {
                    continue;
                }
            }

            $distance = $hasPoint
                ? $this->distanceKm($lat, $lng, (float) $profile->latitude, (float) $profile->longitude)
                : null;

            if ($distance !== null && $radius > 0 && $distance > $radius) {
                continue;
            }

            $matching = $tenant->courts
                ->filter(function (Court $court) use ($io, $surface, $maxPrice): bool {
                    if (Contract::court($court)['status'] !== 'active') {
                        return false;
                    }

                    if ($io !== '' && $court->court_type !== $io) {
                        return false;
                    }

                    if ($surface !== '' && $court->surface_type !== $surface) {
                        return false;
                    }

                    return ! ($maxPrice > 0 && (float) $court->hourly_rate > $maxPrice);
                })
                ->values();

            if ($matching->isEmpty()) {
                continue;
            }

            $settings = $club['settings'];
            $bookable = (bool) ($settings['allow_online_booking'] ?? true);
            $free = $bookable
                ? $this->freeToday($matching, $club['timezone'])
                : ['next' => null, 'count' => 0];

            if ($availableToday && $free['count'] === 0) {
                continue;
            }

            $today = $this->todayHours($matching, $club['timezone']);
            $nowClock = CarbonImmutable::now($club['timezone'])->format('H:i');

            $results[] = [
                'id' => (string) $tenant->id,
                'slug' => (string) $tenant->slug,
                'name' => (string) $tenant->name,
                'tagline' => (string) ($profile->tagline ?? ''),
                'address' => $club['address'],
                'city' => $club['city'],
                'province' => $club['province'],
                'latitude' => (float) $profile->latitude,
                'longitude' => (float) $profile->longitude,
                'distance_km' => $distance,
                'logo_url' => $club['logo_url'],
                'cover_url' => $club['cover_url'],
                'amenities' => $amenities,
                'courts' => $matching->map(fn (Court $court): array => [
                    'id' => (string) $court->id,
                    'name' => (string) $court->name,
                    'indoor_outdoor' => $court->court_type === 'outdoor' ? 'outdoor' : 'indoor',
                    'surface_type' => (string) ($court->surface_type ?? ''),
                    'base_price' => Contract::money($court->hourly_rate),
                ])->all(),
                'price_from' => Contract::money($matching->min(fn (Court $court) => (float) $court->hourly_rate)),
                'currency' => Contract::CURRENCY,
                'open_now' => $today !== null && $nowClock >= $today['opens_at'] && $nowClock < $today['closes_at'],
                'today' => $today,
                'upcoming_session_count' => (int) $tenant->upcoming_session_count,
                'bookable' => $bookable,
                'guest_booking' => $bookable && (bool) ($settings['allow_guest_booking'] ?? false),
                'next_free_at' => $free['next'],
                'free_slots_today' => $free['count'],
            ];
        }

        usort($results, function (array $a, array $b) use ($sort, $hasPoint): int {
            if ($sort === 'price_asc') {
                return ((float) $a['price_from'] <=> (float) $b['price_from']) ?: strcmp($a['name'], $b['name']);
            }

            if ($sort === 'price_desc') {
                return ((float) $b['price_from'] <=> (float) $a['price_from']) ?: strcmp($a['name'], $b['name']);
            }

            if ($sort === 'availability') {
                $soonest = strcmp($a['next_free_at'] ?? '9999', $b['next_free_at'] ?? '9999');

                return $soonest !== 0 ? $soonest : $this->byNear($a, $b);
            }

            // Recommended: bookable first, then nearest (or free today), then name.
            $bookable = (int) $b['bookable'] <=> (int) $a['bookable'];

            if ($bookable !== 0) {
                return $bookable;
            }

            if ($hasPoint && ($near = $this->byNear($a, $b)) !== 0) {
                return $near;
            }

            $free = (int) ($b['free_slots_today'] > 0) <=> (int) ($a['free_slots_today'] > 0);

            return $free !== 0 ? $free : strcmp($a['name'], $b['name']);
        });

        return $this->successResponse(data: $results);
    }

    public function court(Court $court): JsonResponse
    {
        $payload = $this->courtPayload($court);

        if ($payload === null) {
            return $this->errorResponse(message: 'Court not found.', code: Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(data: $payload);
    }

    public function availability(Request $request, Court $court): JsonResponse
    {
        $venue = $this->venue($court);

        if ($venue === null) {
            return $this->errorResponse(message: 'Court not found.', code: Response::HTTP_NOT_FOUND);
        }

        [$tenant, , $club] = $venue;
        $settings = $club['settings'];
        $timezone = $club['timezone'];
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $date = (string) $request->query('date', $today->toDateString());

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: ['date' => ['Choose a date as YYYY-MM-DD.']],
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $length = (int) $request->query('duration_minutes', 60);
        $length = max(15, min($length, 240));
        $windowDays = (int) ($settings['booking_window_days'] ?? 30);

        if ($date < $today->toDateString()) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: ['date' => ['That date has passed.']],
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($date > $today->addDays($windowDays)->toDateString()) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: ['date' => ["{$tenant->name} takes bookings up to {$windowDays} days ahead."]],
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $court->load(['operatingHours', 'priceRules']);

        $weekday = CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone)->dayOfWeek;
        $hours = collect(Contract::hours($court))->firstWhere('weekday', $weekday);

        if (! $hours || $hours['is_closed'] || ! $hours['opens_at'] || ! $hours['closes_at']) {
            return $this->successResponse(data: [], message: 'The court is closed on that day.');
        }

        $opensAt = CarbonImmutable::parse("{$date} {$hours['opens_at']}", $timezone);
        $closesAt = CarbonImmutable::parse("{$date} {$hours['closes_at']}", $timezone);
        $now = CarbonImmutable::now($timezone);

        $slots = [];

        // 30-minute starts, like the mock contract; the last slot must fit inside the day.
        for ($start = $opensAt; $start->addMinutes($length)->lessThanOrEqualTo($closesAt); $start = $start->addMinutes(30)) {
            $end = $start->addMinutes($length);

            $slots[] = [
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
                'available' => $start->greaterThan($now)
                    && $this->availability->isAvailable(
                        $court,
                        $start->toDateTimeString(),
                        $end->toDateTimeString(),
                        null,
                        $timezone,
                    ),
                'price' => Contract::money(Contract::priceForSlot($court, $start, $length)),
                'currency' => Contract::CURRENCY,
            ];

            if (count($slots) >= 96) {
                break;
            }
        }

        return $this->successResponse(data: $slots);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function courtPayload(Court $court): ?array
    {
        $venue = $this->venue($court);

        if ($venue === null) {
            return null;
        }

        [$tenant, $profile, $club] = $venue;
        $settings = $club['settings'];

        $court->load(['operatingHours', 'priceRules']);

        $closures = CourtBlockedSchedule::query()
            ->where('court_id', $court->id)
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (CourtBlockedSchedule $closure): array => [
                'starts_at' => $closure->starts_at->toIso8601String(),
                'ends_at' => $closure->ends_at->toIso8601String(),
                'reason' => (string) ($closure->reason ?? ''),
            ])
            ->values()
            ->all();

        return [
            'id' => (string) $court->id,
            'name' => (string) $court->name,
            'indoor_outdoor' => $court->court_type === 'outdoor' ? 'outdoor' : 'indoor',
            'surface_type' => (string) ($court->surface_type ?? ''),
            'base_price' => Contract::money($court->hourly_rate),
            'court_code' => (string) ($court->court_code ?: 'C-'.$court->id),
            'currency' => Contract::CURRENCY,
            'status' => Contract::court($court)['status'],
            'venue' => [
                'id' => (string) $tenant->id,
                'slug' => (string) $tenant->slug,
                'name' => (string) $tenant->name,
                'address' => $club['address'],
                'city' => $club['city'],
                'province' => $club['province'],
                'latitude' => (float) $profile->latitude,
                'longitude' => (float) $profile->longitude,
                'contact_phone' => $club['contact_phone'],
                'amenities' => array_values((array) ($profile->amenities ?? [])),
                'cancellation_policy' => (string) ($settings['cancellation_policy'] ?? ''),
                'booking_window_days' => (int) ($settings['booking_window_days'] ?? 30),
                'bookable' => (bool) ($settings['allow_online_booking'] ?? true),
            ],
            'hours' => Contract::hours($court),
            'price_rules' => Contract::priceRules($court),
            'closures' => $closures,
        ];
    }

    /**
     * A court is public only while its venue is approved, not read-only, and pinned.
     *
     * @return array{0: Tenant, 1: VenueProfile, 2: array<string, mixed>}|null
     */
    private function venue(Court $court): ?array
    {
        $tenant = $court->tenant;

        if ($tenant === null) {
            return null;
        }

        $tenant->loadMissing(['venueProfile', 'subscription']);

        $profile = $tenant->venueProfile;
        $club = Contract::club($tenant);

        if ($profile === null
            || $club['status'] !== 'active'
            || $club['read_only']
            || $profile->latitude === null
            || $profile->longitude === null) {
            return null;
        }

        return [$tenant, $profile, $club];
    }

    /**
     * Today's opening range across the courts, in the venue's own time.
     *
     * @param  Collection<int, Court>  $courts
     * @return array{opens_at: string, closes_at: string}|null
     */
    private function todayHours($courts, string $timezone): ?array
    {
        $weekday = CarbonImmutable::now($timezone)->dayOfWeek;
        $opens = [];
        $closes = [];

        foreach ($courts as $court) {
            $court->loadMissing('operatingHours');
            $hours = collect(Contract::hours($court))->firstWhere('weekday', $weekday);

            if ($hours && ! $hours['is_closed'] && $hours['opens_at'] && $hours['closes_at']) {
                $opens[] = $hours['opens_at'];
                $closes[] = $hours['closes_at'];
            }
        }

        if ($opens === []) {
            return null;
        }

        sort($opens);
        sort($closes);

        return ['opens_at' => $opens[0], 'closes_at' => $closes[count($closes) - 1]];
    }

    /**
     * Free one-hour starts left today across these courts, and the earliest.
     *
     * @param  Collection<int, Court>  $courts
     * @return array{next: string|null, count: int}
     */
    private function freeToday($courts, string $timezone): array
    {
        $date = CarbonImmutable::now($timezone)->toDateString();
        $now = CarbonImmutable::now($timezone);
        $weekday = CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone)->dayOfWeek;

        $next = null;
        $count = 0;

        foreach ($courts as $court) {
            $court->loadMissing(['operatingHours', 'priceRules']);
            $hours = collect(Contract::hours($court))->firstWhere('weekday', $weekday);

            if (! $hours || $hours['is_closed'] || ! $hours['opens_at'] || ! $hours['closes_at']) {
                continue;
            }

            $opensAt = CarbonImmutable::parse("{$date} {$hours['opens_at']}", $timezone);
            $closesAt = CarbonImmutable::parse("{$date} {$hours['closes_at']}", $timezone);

            for ($start = $opensAt; $start->addHour()->lessThanOrEqualTo($closesAt); $start = $start->addHour()) {
                if ($start->lessThanOrEqualTo($now)) {
                    continue;
                }

                $end = $start->addHour();

                if (! $this->availability->isAvailable(
                    $court,
                    $start->toDateTimeString(),
                    $end->toDateTimeString(),
                    null,
                    $timezone,
                )) {
                    continue;
                }

                $count++;
                $iso = $start->toIso8601String();

                if ($next === null || $iso < $next) {
                    $next = $iso;
                }
            }
        }

        return ['next' => $next, 'count' => $count];
    }

    private function byNear(array $a, array $b): int
    {
        if ($a['distance_km'] === null || $b['distance_km'] === null) {
            return 0;
        }

        return $a['distance_km'] <=> $b['distance_km'];
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }
}
