<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Booking;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\ClubMembershipPlan;
use App\Models\Court;
use App\Models\OpenPlaySession;
use App\Models\Tenant;
use App\Support\Compat\CommunityView;
use App\Support\Compat\Contract;
use App\Support\Compat\MembershipView;
use App\Support\Compat\PlayerBookingView;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The club directory, one club's page, and the courts a host can pick when
 * creating an open-play session (packages/mocks/src/handlers-open-play.ts).
 */
class CommunityController extends Controller
{
    use ApiResponse;

    /**
     * `GET /discovery/clubs?search=&city=&type=`
     *
     * Communities by default; venues only when asked for with type=venue. Your
     * own clubs still in review stay visible to you.
     */
    public function index(Request $request): JsonResponse
    {
        $viewer = (int) $request->user()->id;
        $type = (string) $request->query('type', '');
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $city = mb_strtolower(trim((string) $request->query('city', '')));

        if ($type === 'venue') {
            $listings = Tenant::query()
                ->with(['venueProfile', 'subscription', 'courts'])
                ->get()
                ->filter(function (Tenant $tenant): bool {
                    $club = Contract::club($tenant);

                    return $tenant->venueProfile !== null
                        && $club['status'] === 'active'
                        && ! $club['read_only'];
                })
                ->map(fn (Tenant $tenant): array => CommunityView::venueListing($tenant));
        } else {
            $listings = Club::query()
                ->with('homeVenue')
                ->get()
                ->filter(fn (Club $club): bool => ($club->status ?: 'active') === 'active'
                    || CommunityView::canManageCommunity($club, $viewer))
                ->map(fn (Club $club): array => CommunityView::communityListing($club));
        }

        $listings = $listings
            ->filter(function (array $listing) use ($search, $city): bool {
                if ($search !== '') {
                    $haystack = mb_strtolower($listing['name'].' '.$listing['city'].' '.$listing['tagline']);

                    if (! str_contains($haystack, $search)) {
                        return false;
                    }
                }

                return $city === '' || mb_strtolower($listing['city']) === $city;
            })
            ->sortByDesc(fn (array $listing): string => sprintf(
                '%04d-%06d-%s',
                $listing['live_session_count'],
                $listing['member_count'],
                $listing['name'],
            ))
            ->values()
            ->all();

        return $this->successResponse(data: $listings);
    }

    /**
     * `GET /discovery/clubs/:type/:slug`
     *
     * A venue page is public; a community page only shows once it is active, or
     * to the people who run it.
     */
    public function show(Request $request, string $type, string $slug): JsonResponse
    {
        $viewer = $request->user()?->id;
        $viewerId = $viewer === null ? null : (int) $viewer;

        if (! in_array($type, ['venue', 'community'], true)) {
            return $this->errorResponse(message: 'Club not found.', code: Response::HTTP_NOT_FOUND);
        }

        if ($type === 'venue') {
            $tenant = Tenant::query()->where('slug', $slug)->first();

            if ($tenant === null) {
                return $this->errorResponse(message: 'Club not found.', code: Response::HTTP_NOT_FOUND);
            }

            $tenant->loadMissing(['venueProfile', 'subscription']);
            $club = Contract::club($tenant);

            if ($tenant->venueProfile === null || $club['status'] !== 'active' || $club['read_only']) {
                return $this->errorResponse(message: 'Club not found.', code: Response::HTTP_NOT_FOUND);
            }

            return $this->successResponse(data: CommunityView::venueProfile($tenant, $viewerId));
        }

        $club = Club::query()->where('slug', $slug)->first();

        if ($club === null) {
            return $this->errorResponse(message: 'Club not found.', code: Response::HTTP_NOT_FOUND);
        }

        $status = $club->status ?: 'active';
        $manager = CommunityView::canManageCommunity($club, $viewerId);

        if ($status !== 'active' && ! $manager) {
            return $this->errorResponse(message: 'Club not found.', code: Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(data: CommunityView::communityProfile($club, $viewerId));
    }

    /**
     * `POST /communities` — start a community club.
     *
     * The creator becomes its owner and gets a free "Member" plan, the same
     * starter state the SPA's mock produces. CourtFlex review still applies.
     */
    public function store(Request $request): JsonResponse
    {
        $viewer = (int) $request->user()->id;
        $input = $this->communityInput($request);
        $errors = $this->communityErrors($input, null);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $values = $this->cleanCommunity($input);
        $club = DB::transaction(function () use ($values, $viewer): Club {
            $club = Club::query()->create($values + [
                'creator_id' => $viewer,
                'slug' => $this->uniqueSlug((string) $values['name']),
                'status' => 'pending_review',
            ]);

            ClubMembershipPlan::query()->create([
                'club_id' => $club->id,
                'name' => 'Member',
                'description' => 'Free.',
                'price' => 0,
                'currency' => Contract::CURRENCY,
                'duration_days' => null,
                'session_discount_percent' => 0,
                'perks' => [],
                'recommended' => false,
                'is_active' => true,
            ]);

            $member = ClubMember::query()->create([
                'club_id' => $club->id,
                'user_id' => $viewer,
                'role' => 'owner',
                'status' => 'active',
                'joined_at' => now(),
                'requested_at' => now(),
                'starts_at' => now(),
                'dues_due' => 0,
            ]);

            MembershipView::log($member, 'approved', $viewer, 'Started the club');

            return $club;
        });

        return $this->successResponse(data: CommunityView::communityProfile($club->fresh(), $viewer), code: 201);
    }

    /**
     * `PATCH /communities/:id` — edit a community's public details.
     */
    public function update(Request $request, Club $club): JsonResponse
    {
        $viewer = (int) $request->user()->id;

        if (! CommunityView::canManageCommunity($club, $viewer)) {
            return $this->errorResponse(
                message: "Only the club's admins can change its details.",
                code: Response::HTTP_FORBIDDEN,
            );
        }

        $input = $this->communityInput($request);
        $errors = $this->communityErrors($input, $club);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $club->update($this->cleanCommunity($input));

        return $this->successResponse(data: CommunityView::communityProfile($club->fresh(), $viewer));
    }

    /**
     * @return array<string, mixed>
     */
    private function communityInput(Request $request): array
    {
        $input = $request->all();
        $options = is_array($input['payment_options'] ?? null) ? $input['payment_options'] : [];

        foreach (['gcash', 'maya'] as $wallet) {
            if (is_array($options[$wallet] ?? null)) {
                $options[$wallet]['account_number'] = str_replace(
                    ' ',
                    '',
                    (string) ($options[$wallet]['account_number'] ?? ''),
                );
            }
        }

        $input['payment_options'] = $options;

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, list<string>>
     */
    private function communityErrors(array $input, ?Club $existing): array
    {
        $options = is_array($input['payment_options'] ?? null) ? $input['payment_options'] : [];
        $hasPayment = (bool) ($options['in_person'] ?? false)
            || is_array($options['gcash'] ?? null)
            || is_array($options['maya'] ?? null);

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'min:3'],
            'tagline' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'rules' => ['nullable', 'string'],
            'city' => ['required', 'string'],
            'province' => ['required', 'string'],
            'home_venue_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'contact_email' => ['nullable', 'email'],
            'contact_phone' => ['nullable', 'string'],
            'join_policy' => ['required', 'in:open,approval'],
            'cancellation_hours' => ['required', 'integer', 'between:0,72'],
            'logo_url' => ['nullable', 'string', 'max:1500000', 'regex:/^(data:image\/(png|jpeg|webp);base64,|https:\/\/)/'],
            'cover_url' => ['nullable', 'string', 'max:1500000', 'regex:/^(data:image\/(png|jpeg|webp);base64,|https:\/\/)/'],
            'waiver' => ['required', 'string', 'min:40', 'max:6000'],
            'payment_options.in_person' => ['boolean'],
            'payment_options.gcash' => ['nullable', 'array'],
            'payment_options.maya' => ['nullable', 'array'],
            'payment_options.instructions' => ['nullable', 'string', 'max:300'],
        ], [
            'name.min' => 'Give the club a name of at least 3 letters.',
            'home_venue_id.exists' => 'Choose a venue from the list.',
            'waiver.min' => 'Write the agreement members sign when they join (at least a few sentences).',
            'waiver.max' => 'Keep the agreement under 6,000 characters.',
        ]);

        $errors = $validator->errors()->toArray();

        if (! $hasPayment) {
            $errors['payment_options'] = ['Choose at least one way members can pay.'];
        }

        foreach (['gcash', 'maya'] as $wallet) {
            $row = is_array($options[$wallet] ?? null) ? $options[$wallet] : null;

            if ($row === null) {
                continue;
            }

            if (trim((string) ($row['account_name'] ?? '')) === '') {
                $errors[$wallet.'_name'] = ['Enter the account name members will see.'];
            }

            if (preg_match('/^09\d{9}$/', (string) ($row['account_number'] ?? '')) !== 1) {
                $errors[$wallet.'_number'] = ['Enter the 11-digit number, like 09171234567.'];
            }

            if (filled($row['qr_url'] ?? null)
                && preg_match('/^data:image\/(png|jpeg|webp);base64,/', (string) $row['qr_url']) !== 1) {
                $errors[$wallet.'_qr'] = ['Upload the QR code as a PNG, JPG or WebP image.'];
            }
        }

        $name = trim((string) ($input['name'] ?? ''));
        $duplicate = Club::query()
            ->where('id', '!=', $existing?->id ?? 0)
            ->where(fn ($query) => $query->where('name', $name)->orWhere('slug', Str::slug($name)))
            ->exists();

        if ($name !== '' && $duplicate) {
            $errors['name'] = [$existing === null
                ? 'A club with this name already exists. Choose another name.'
                : 'A club with this name already exists.'];
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function cleanCommunity(array $input): array
    {
        $options = is_array($input['payment_options'] ?? null) ? $input['payment_options'] : [];
        $wallets = [];

        foreach (['gcash', 'maya'] as $wallet) {
            $row = is_array($options[$wallet] ?? null) ? $options[$wallet] : null;
            $wallets[$wallet] = $row === null ? null : [
                'account_name' => trim((string) ($row['account_name'] ?? '')),
                'account_number' => str_replace(' ', '', (string) ($row['account_number'] ?? '')),
                'qr_url' => $row['qr_url'] ?? null,
            ];
        }

        return [
            'name' => trim((string) $input['name']),
            'tagline' => trim((string) $input['tagline']),
            'description' => trim((string) ($input['description'] ?? '')),
            'rules' => trim((string) ($input['rules'] ?? '')),
            'city' => trim((string) $input['city']),
            'province' => trim((string) $input['province']),
            'home_venue_id' => filled($input['home_venue_id'] ?? null) ? (int) $input['home_venue_id'] : null,
            'contact_email' => trim((string) ($input['contact_email'] ?? '')),
            'contact_phone' => trim((string) ($input['contact_phone'] ?? '')),
            'join_policy' => (string) $input['join_policy'],
            'cancellation_hours' => (int) $input['cancellation_hours'],
            'logo' => filled($input['logo_url'] ?? null) ? (string) $input['logo_url'] : null,
            'cover' => filled($input['cover_url'] ?? null) ? (string) $input['cover_url'] : null,
            'waiver' => trim((string) $input['waiver']),
            'payment_options' => [
                'in_person' => (bool) ($options['in_person'] ?? false),
                'gcash' => $wallets['gcash'],
                'maya' => $wallets['maya'],
                'instructions' => trim((string) ($options['instructions'] ?? '')),
            ],
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'club';
        $slug = mb_substr($base, 0, 48);
        $suffix = 1;

        while (Club::query()->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = mb_substr($base, 0, 44).'-'.$suffix;
        }

        return $slug;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function invalid(array $errors): JsonResponse
    {
        return $this->errorResponse(
            message: 'The given data was invalid.',
            errors: $errors,
            code: Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * `GET /venues/:venue/court-options?starts_at=&ends_at=&exclude_session=`
     *
     * Every active court with whether it is free for that range, why not, and
     * the price for the range. Checked again when the session is saved.
     */
    public function courtOptions(Request $request, string $venue): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $venue)->first();

        if ($tenant === null && ctype_digit($venue)) {
            $tenant = Tenant::query()->find((int) $venue);
        }

        if ($tenant === null) {
            return $this->errorResponse(message: 'Venue not found.', code: Response::HTTP_NOT_FOUND);
        }

        $tenant->loadMissing(['venueProfile', 'subscription']);
        $club = Contract::club($tenant);

        if ($club['status'] !== 'active' || $club['read_only']) {
            return $this->errorResponse(message: 'Venue not found.', code: Response::HTTP_NOT_FOUND);
        }

        $rawStart = trim((string) $request->query('starts_at', ''));
        $rawEnd = trim((string) $request->query('ends_at', ''));
        $errors = [];

        if ($rawStart === '') {
            $errors['starts_at'][] = 'Choose the start time.';
        }

        if ($rawEnd === '') {
            $errors['ends_at'][] = 'Choose the end time.';
        }

        $timezone = (string) $club['timezone'];
        $start = null;
        $end = null;

        if ($errors === []) {
            try {
                $start = CarbonImmutable::parse($rawStart)->setTimezone($timezone);
                $end = CarbonImmutable::parse($rawEnd)->setTimezone($timezone);
            } catch (Throwable) {
                $errors['starts_at'][] = 'Choose the start time.';
            }
        }

        if ($start !== null && $end !== null && $end->lessThanOrEqualTo($start)) {
            $errors['ends_at'][] = 'The end must be after the start.';
        }

        if ($errors !== []) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: $errors,
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $minutes = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
        $exclude = trim((string) $request->query('exclude_session', ''));

        $tenant->loadMissing('courts');
        $options = $tenant->courts
            ->filter(fn (Court $court): bool => Contract::court($court)['status'] === 'active')
            ->map(function (Court $court) use ($start, $end, $minutes, $timezone, $exclude): array {
                $court->loadMissing(['operatingHours', 'priceRules']);

                return [
                    'id' => (string) $court->id,
                    'name' => (string) $court->name,
                    'indoor_outdoor' => $court->court_type === 'outdoor' ? 'outdoor' : 'indoor',
                    'available' => ($reason = $this->unavailableReason($court, $start, $end, $timezone, $exclude)) === null,
                    'reason' => $reason,
                    'price' => PlayerBookingView::lines($court, $start, $minutes)[1],
                ];
            })
            ->values()
            ->all();

        return $this->successResponse(data: $options);
    }

    /**
     * Why a court cannot host that range, or null when it can.
     */
    private function unavailableReason(
        Court $court,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $timezone,
        string $exclude,
    ): ?string {
        $status = Contract::court($court)['status'];

        if ($status !== 'active') {
            return $status === 'maintenance' ? 'Under maintenance.' : 'Not taking bookings.';
        }

        $hours = collect(Contract::hours($court))->firstWhere('weekday', $start->dayOfWeek);

        if (! $hours || $hours['is_closed'] || ! $hours['opens_at'] || ! $hours['closes_at']) {
            return 'Closed that day.';
        }

        if ($start->format('H:i') < $hours['opens_at'] || $end->format('H:i') > $hours['closes_at']) {
            return "Outside opening hours ({$hours['opens_at']}–{$hours['closes_at']}).";
        }

        $booking = Booking::query()
            ->where('court_id', $court->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->first();

        if ($booking !== null) {
            return 'Taken '.$this->range($booking->starts_at, $booking->ends_at).'.';
        }

        $session = OpenPlaySession::query()
            ->whereIn('status', CommunityView::ACTIVE_SESSION_STATUSES)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($exclude !== '' && ctype_digit($exclude), fn ($query) => $query->where('id', '!=', (int) $exclude))
            ->whereHas('courts', fn ($query) => $query->where('courts.id', $court->id))
            ->orderBy('starts_at')
            ->first();

        if ($session !== null) {
            return 'Taken by open play '.$this->range($session->starts_at, $session->ends_at).'.';
        }

        return null;
    }

    /**
     * Stored booking/session times are the venue's wall clock, so they are read
     * as-is (converting again would shift them by the offset).
     */
    private function range(mixed $from, mixed $to): string
    {
        return $from->format('H:i').'–'.$to->format('H:i');
    }
}
