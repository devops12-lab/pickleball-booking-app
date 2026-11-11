<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesClub;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Court;
use App\Models\CourtImage;
use App\Models\CourtOperatingHour;
use App\Models\Tenant;
use App\Services\BookingAvailabilityService;
use App\Services\FileUploadService;
use App\Support\Compat\Contract;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/clubs/{club}/courts — the SPA's Court and CourtDetail contracts.
 */
class CourtController extends Controller
{
    use ApiResponse, AuthorizesClub;

    public function __construct(
        protected BookingAvailabilityService $availabilityService
    ) {}

    public function index(Tenant $club): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        $courts = $club->courts()->with('images')->orderBy('id')->get();

        return $this->successResponse(data: $courts->map(fn (Court $court) => Contract::court($court))->all());
    }

    public function store(Request $request, Tenant $club): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'court_code' => ['nullable', 'string', 'max:50'],
            'indoor_outdoor' => ['required', 'in:indoor,outdoor'],
            'surface_type' => ['required', 'string', 'max:100'],
            'base_price' => ['required', 'regex:/^-?\d+\.\d{2}$/'],
            'status' => ['required', 'in:active,inactive,maintenance'],
        ]);

        $facility = $club->facilities()->orderBy('id')->first();

        if (! $facility) {
            return $this->errorResponse(
                'This club has no facility yet, so a court cannot be created.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $court = Court::create([
            'facility_id' => $facility->id,
            'tenant_id' => $club->id,
            'name' => $data['name'],
            'court_code' => $data['court_code'] ?? null,
            'court_type' => $data['indoor_outdoor'],
            'surface_type' => $data['surface_type'],
            'hourly_rate' => $data['base_price'],
            'status' => $data['status'],
            'is_active' => $data['status'] === 'active',
        ]);

        return $this->successResponse(
            data: Contract::courtDetail($court->load(['operatingHours', 'priceRules', 'images'])),
            message: 'Court created.',
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Upload 1 to 5 gallery photos for a court.
     */
    public function addImages(Request $request, Tenant $club, Court $court): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $court->tenant_id)) {
            return $this->errorResponse('Court not found in this club.', code: Response::HTTP_NOT_FOUND);
        }

        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.CourtImage::MAX_PER_COURT],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $existing = $court->images()->count();
        $incoming = count((array) $request->file('images'));

        if ($existing + $incoming > CourtImage::MAX_PER_COURT) {
            return $this->errorResponse(
                'A court can hold at most '.CourtImage::MAX_PER_COURT.' photos; this court already has '.$existing.'.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $upload = app(FileUploadService::class);
        $nextOrder = (int) $court->images()->max('sort_order');

        foreach ((array) $request->file('images') as $file) {
            $nextOrder++;

            $court->images()->create([
                'image_path' => $upload->upload($file, 'courts'),
                'sort_order' => $nextOrder,
            ]);
        }

        return $this->successResponse(
            data: Contract::court($court->load('images')),
            message: 'Photos added.'
        );
    }

    /**
     * Remove one photo, keeping at least one on the court.
     */
    public function deleteImage(Tenant $club, Court $court, CourtImage $image): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $court->tenant_id) || $image->court_id !== $court->id) {
            return $this->errorResponse('Photo not found on this court.', code: Response::HTTP_NOT_FOUND);
        }

        if ($court->images()->count() <= 1) {
            return $this->errorResponse(
                'A court needs at least one photo. Add another before removing this one.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        app(FileUploadService::class)->delete($image->image_path);
        $image->delete();

        return $this->successResponse(
            data: Contract::court($court->load('images')),
            message: 'Photo removed.'
        );
    }

    /**
     * Reorder the gallery: the ids arrive in the order they should be shown.
     */
    public function reorderImages(Request $request, Tenant $club, Court $court): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $court->tenant_id)) {
            return $this->errorResponse('Court not found in this club.', code: Response::HTTP_NOT_FOUND);
        }

        $data = $request->validate([
            'image_ids' => ['required', 'array', 'min:1'],
            'image_ids.*' => ['required', 'integer'],
        ]);

        foreach (array_values($data['image_ids']) as $index => $imageId) {
            CourtImage::query()
                ->where('id', $imageId)
                ->where('court_id', $court->id)
                ->update(['sort_order' => $index + 1]);
        }

        return $this->successResponse(
            data: Contract::court($court->load('images')),
            message: 'Photo order saved.'
        );
    }

    public function show(Tenant $club, Court $court): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $court->tenant_id)) {
            return $this->errorResponse('Court not found in this club.', code: Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(
            data: Contract::courtDetail($court->load(['operatingHours', 'priceRules', 'images']))
        );
    }

    public function update(Request $request, Tenant $club, Court $court): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $court->tenant_id)) {
            return $this->errorResponse('Court not found in this club.', code: Response::HTTP_NOT_FOUND);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'court_code' => ['sometimes', 'string', 'max:50'],
            'indoor_outdoor' => ['sometimes', 'in:indoor,outdoor'],
            'surface_type' => ['sometimes', 'string', 'max:100'],
            'base_price' => ['sometimes', 'regex:/^-?\d+\.\d{2}$/'],
            'status' => ['sometimes', 'in:active,inactive,maintenance'],
        ]);

        $court->fill([
            'name' => $data['name'] ?? $court->name,
            'court_code' => $data['court_code'] ?? $court->court_code,
            'court_type' => $data['indoor_outdoor'] ?? $court->court_type,
            'surface_type' => $data['surface_type'] ?? $court->surface_type,
            'hourly_rate' => $data['base_price'] ?? $court->hourly_rate,
        ]);

        if (isset($data['status'])) {
            // Keep the SPA's own status (so maintenance survives) and mirror it
            // onto the bookable flag the rest of this backend reads.
            $court->status = $data['status'];
            $court->is_active = $data['status'] === 'active';
        }

        $court->save();

        return $this->successResponse(
            data: Contract::courtDetail($court->load(['operatingHours', 'priceRules', 'images'])),
            message: 'Court updated.'
        );
    }

    public function updateHours(Request $request, Tenant $club, Court $court): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $court->tenant_id)) {
            return $this->errorResponse('Court not found in this club.', code: Response::HTTP_NOT_FOUND);
        }

        $data = $request->validate([
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6'],
            'hours.*.is_closed' => ['required', 'boolean'],
            'hours.*.opens_at' => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'hours.*.closes_at' => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        foreach ($data['hours'] as $row) {
            CourtOperatingHour::updateOrCreate(
                ['court_id' => $court->id, 'day_of_week' => (int) $row['weekday']],
                [
                    'is_closed' => (bool) $row['is_closed'],
                    // Both time columns are NOT NULL: a closed day keeps 00:00 and
                    // is reported back as null opening times by Contract::hours().
                    'open_time' => $row['is_closed'] ? '00:00' : ($row['opens_at'] ?? '00:00'),
                    'close_time' => $row['is_closed'] ? '00:00' : ($row['closes_at'] ?? '00:00'),
                ]
            );
        }

        return $this->successResponse(
            data: Contract::courtDetail($court->load(['operatingHours', 'priceRules'])),
            message: 'Court hours saved.'
        );
    }

    public function updatePriceRules(Request $request, Tenant $club, Court $court): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $court->tenant_id)) {
            return $this->errorResponse('Court not found in this club.', code: Response::HTTP_NOT_FOUND);
        }

        $data = $request->validate([
            'rules' => ['present', 'array'],
            'rules.*.day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'rules.*.starts_at' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'rules.*.ends_at' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'rules.*.price' => ['required', 'regex:/^-?\d+\.\d{2}$/'],
        ]);

        $court->priceRules()->delete();

        foreach ($data['rules'] as $rule) {
            $court->priceRules()->create([
                'day_of_week' => $rule['day_of_week'] ?? null,
                'starts_at' => $rule['starts_at'],
                'ends_at' => $rule['ends_at'],
                'price' => $rule['price'],
            ]);
        }

        return $this->successResponse(
            data: Contract::courtDetail($court->load(['operatingHours', 'priceRules'])),
            message: 'Price rules saved.'
        );
    }

    /**
     * Slot-by-slot availability for one day, priced per slot.
     */
    public function availability(Request $request, Tenant $club, Court $court): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        if (! $this->belongsToClub($club, $court->tenant_id)) {
            return $this->errorResponse('Court not found in this club.', code: Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'duration_minutes' => ['nullable', 'integer', 'between:15,240'],
        ]);

        $minutes = (int) ($validated['duration_minutes'] ?? 60);
        $timezone = $club->venueProfile?->timezone ?: 'Asia/Manila';

        $court->load(['operatingHours', 'priceRules']);

        $hours = collect(Contract::hours($court))
            ->firstWhere('weekday', CarbonImmutable::createFromFormat('Y-m-d', $validated['date'], $timezone)->dayOfWeek);

        if (! $hours || $hours['is_closed'] || ! $hours['opens_at'] || ! $hours['closes_at']) {
            return $this->successResponse(data: [], message: 'The court is closed on that day.');
        }

        $day = $validated['date'];
        $opensAt = CarbonImmutable::parse("{$day} {$hours['opens_at']}", $timezone);
        $closesAt = CarbonImmutable::parse("{$day} {$hours['closes_at']}", $timezone);

        $slots = [];

        for ($start = $opensAt; $start->addMinutes($minutes)->lessThanOrEqualTo($closesAt); $start = $start->addMinutes($minutes)) {
            $end = $start->addMinutes($minutes);

            $slots[] = [
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
                'available' => $this->availabilityService->isAvailable(
                    $court,
                    $start->toDateTimeString(),
                    $end->toDateTimeString(),
                    null,
                    $timezone
                ),
                'price' => Contract::money(Contract::priceForSlot($court, $start, $minutes)),
                'currency' => Contract::CURRENCY,
            ];

            if (count($slots) >= 96) {
                break;
            }
        }

        return $this->successResponse(data: $slots);
    }
}
