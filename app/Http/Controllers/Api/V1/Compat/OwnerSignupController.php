<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Court;
use App\Models\CourtOperatingHour;
use App\Models\Facility;
use App\Models\GeoLocation;
use App\Models\OwnerSignupCode;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\VenueProfile;
use App\Services\Auth\AccountProvisioningService;
use App\Services\Mail\EmailSettingsService;
use App\Support\Compat\PlatformView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owner sign-up (packages/mocks/src/handlers-player.ts).
 *
 * A visitor confirms their email with a six-digit code, then sends the venue,
 * its first courts and their account. The venue is stored as a tenant with a
 * `pending_review` profile, an owner membership and a Starter trial, so the
 * owner can start setting it up while CourtFlex reviews it. A signed-in player
 * skips the code: the venue joins their existing account.
 */
class OwnerSignupController extends Controller
{
    use ApiResponse;

    private const CODE_TTL_MINUTES = 10;

    private const RESEND_SECONDS = 60;

    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly AccountProvisioningService $provisioning,
        private readonly EmailSettingsService $mail,
    ) {}

    /**
     * `POST /owner-signup/code` — send the email confirmation code.
     *
     * Mail is logged in local development, so the code is written to the log
     * the same way a delivered message would be.
     */
    public function code(Request $request): JsonResponse
    {
        if (! self::signupsOpen()) {
            return $this->errorResponse(
                message: "CourtFlex isn't taking new venues right now. ".PlatformView::supportLine(),
                code: Response::HTTP_FORBIDDEN,
            );
        }

        $name = trim((string) $request->input('name', ''));
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $errors = [];

        if (mb_strlen($name) < 2) {
            $errors['name'][] = 'Enter your full name.';
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'Enter a valid email. The code goes there.';
        } elseif (User::query()->where('email', $email)->exists()) {
            $errors['email'][] = 'This email already has a CourtFlex account. Sign in first, then add your venue.';
        }

        if ($errors !== []) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: $errors,
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $justSent = OwnerSignupCode::query()
            ->where('email', $email)
            ->whereNull('used_at')
            ->where('last_sent_at', '>', now()->subSeconds(self::RESEND_SECONDS))
            ->exists();

        if ($justSent) {
            return $this->errorResponse(
                message: 'A code was just sent. Wait a minute before asking for another.',
                code: Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $sentAt = now();

        $row = OwnerSignupCode::create([
            'name' => $name,
            'email' => $email,
            'code_hash' => hash('sha256', $code),
            'attempts' => 0,
            'last_sent_at' => $sentAt,
            'expires_at' => $sentAt->copy()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        $this->mail->trySend(
            $email,
            'Your CourtFlex venue sign-up code',
            [
                "Your venue sign-up code is {$code}.",
                'It expires in 10 minutes.',
            ],
        );

        return $this->successResponse(data: [
            'verification_id' => (string) $row->id,
            'email' => $email,
            'expires_at' => $row->expires_at->toIso8601String(),
            'resend_after' => $sentAt->copy()->addSeconds(self::RESEND_SECONDS)->toIso8601String(),
        ], code: Response::HTTP_CREATED);
    }

    /**
     * `POST /owner-signup/verify` — check the six-digit code.
     */
    public function verify(Request $request): JsonResponse
    {
        $row = OwnerSignupCode::query()->find((int) $request->input('verification_id', 0));

        if ($row === null || $row->isUsed()) {
            return $this->invalidCode('Ask for a new code.');
        }

        if ($row->isExpired()) {
            return $this->invalidCode('That code has expired. Ask for a new one.');
        }

        if ($row->attempts >= self::MAX_ATTEMPTS) {
            return $this->invalidCode('Too many wrong codes. Ask for a new one.');
        }

        $code = trim((string) $request->input('code', ''));

        if (! hash_equals($row->code_hash, hash('sha256', $code))) {
            $row->increment('attempts');

            return $this->invalidCode(
                "That code doesn't match. ".max(0, self::MAX_ATTEMPTS - $row->attempts).' tries left.'
            );
        }

        $row->forceFill(['verified_at' => now()])->save();

        return $this->successResponse(data: ['verified' => true]);
    }

    /**
     * `POST /owner-signup` — create the venue, its first courts and the owner.
     */
    public function store(Request $request): JsonResponse
    {
        if (! self::signupsOpen()) {
            return $this->errorResponse(
                message: "CourtFlex isn't taking new venues right now. ".PlatformView::supportLine(),
                code: Response::HTTP_FORBIDDEN,
            );
        }

        $signedIn = Auth::guard('sanctum')->user() ?? Auth::guard('web')->user();

        $venue = (array) $request->input('venue', []);
        $courts = (array) $request->input('courts', []);
        $owner = (array) $request->input('owner', []);

        $name = trim((string) ($venue['name'] ?? ''));
        $latitude = $venue['latitude'] ?? null;
        $longitude = $venue['longitude'] ?? null;
        $barangayId = trim((string) ($venue['barangay_id'] ?? ''));
        $address = trim((string) ($venue['address'] ?? ''));
        $courtCount = filter_var($venue['court_count'] ?? null, FILTER_VALIDATE_INT);
        $phone = preg_replace('/[\s-]/', '', (string) ($venue['contact_phone'] ?? ''));
        $contactEmail = mb_strtolower(trim((string) ($venue['contact_email'] ?? '')));

        $errors = [];

        if (mb_strlen($name) < 3) {
            $errors['venue.name'][] = "Enter the venue's name, as players will see it.";
        } elseif (Tenant::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            $errors['venue.name'][] = 'A venue with this name is already on CourtFlex. Add the branch or area to it.';
        }

        if (! is_numeric($latitude) || ! is_numeric($longitude)
            || (float) $latitude < 4.5 || (float) $latitude > 21.5
            || (float) $longitude < 116 || (float) $longitude > 127) {
            $errors['venue.pin'][] = 'Put the pin on your venue in the Philippines.';
        }

        $chain = GeoLocation::chain($barangayId);

        if ($chain === null) {
            $errors['venue.barangay_id'][] = "Choose the venue's barangay.";
        }

        if ($address === '') {
            $errors['venue.address'][] = 'Enter the street, building or landmark.';
        }

        if ($courtCount === false || $courtCount < 1 || $courtCount > 10) {
            $errors['venue.court_count'][] = 'Choose 1 to 10 courts. You can add more on a paid plan.';
        }

        if (! preg_match('/^(\+?63|0)9\d{9}$/', $phone)) {
            $errors['venue.contact_phone'][] = "Enter the venue's mobile number, like 09171234567.";
        }

        if ($contactEmail !== '' && ! filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['venue.contact_email'][] = 'Enter a valid email, or leave it empty.';
        }

        $indoorOutdoor = (string) ($courts['indoor_outdoor'] ?? '');
        $opensAt = (string) ($courts['opens_at'] ?? '');
        $closesAt = (string) ($courts['closes_at'] ?? '');
        $hourlyRate = $courts['hourly_rate'] ?? null;
        $halfHour = '/^([01]\d|2[0-3]):(00|30)$/';

        if (! in_array($indoorOutdoor, ['indoor', 'outdoor'], true)) {
            $errors['courts.indoor_outdoor'][] = 'Choose indoor or outdoor.';
        }

        if (! preg_match($halfHour, $opensAt)) {
            $errors['courts.opens_at'][] = 'Choose an opening time.';
        }

        if (! preg_match($halfHour, $closesAt)) {
            $errors['courts.closes_at'][] = 'Choose a closing time.';
        } elseif (preg_match($halfHour, $opensAt) && $closesAt <= $opensAt) {
            $errors['courts.closes_at'][] = 'Close after you open.';
        }

        if (! is_numeric($hourlyRate) || (float) $hourlyRate < 50 || (float) $hourlyRate > 5000) {
            $errors['courts.hourly_rate'][] = 'Enter an hourly rate from ₱50 to ₱5,000.';
        }

        $newAccount = $signedIn === null;
        $ownerName = '';
        $ownerEmail = '';
        $ownerPassword = '';
        $verification = null;

        if ($newAccount) {
            $ownerName = trim((string) ($owner['name'] ?? ''));
            $ownerEmail = mb_strtolower(trim((string) ($owner['email'] ?? '')));
            $ownerPassword = (string) ($owner['password'] ?? '');

            if (mb_strlen($ownerName) < 2) {
                $errors['owner.name'][] = 'Enter your full name.';
            }

            if (! filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
                $errors['owner.email'][] = 'Enter a valid email.';
            } elseif (User::query()->where('email', $ownerEmail)->exists()) {
                $errors['owner.email'][] = 'This email already has a CourtFlex account. Sign in first, then add your venue.';
            }

            if (mb_strlen($ownerPassword) < 8
                || ! preg_match('/[A-Za-z]/', $ownerPassword)
                || ! preg_match('/\d/', $ownerPassword)) {
                $errors['owner.password'][] = 'Use at least 8 characters with a letter and a number.';
            }

            if ((string) ($owner['password_confirmation'] ?? '') !== $ownerPassword) {
                $errors['owner.password_confirmation'][] = "The passwords don't match.";
            }

            $verification = OwnerSignupCode::query()->find((int) ($owner['verification_id'] ?? 0));

            if ($verification === null
                || ! $verification->isVerified()
                || $verification->isUsed()
                || $verification->isExpired()
                || $verification->email !== $ownerEmail) {
                $errors['owner.code'][] = 'Confirm your email with the code we sent.';
            }
        }

        if ($errors !== []) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: $errors,
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $slug = $this->uniqueSlug($name);
        $phone = preg_replace('/^\+63/', '0', (string) $phone);
        $plan = SubscriptionPlan::query()->where('slug', 'starter')->first();
        $trialDays = (int) PlatformSetting::get('trial_days', 14);
        $timezone = 'Asia/Manila';

        $result = DB::transaction(function () use (
            $signedIn, $newAccount, $ownerName, $ownerEmail, $ownerPassword, $verification,
            $name, $slug, $phone, $contactEmail, $address, $chain, $barangayId,
            $latitude, $longitude, $courtCount, $indoorOutdoor, $opensAt, $closesAt,
            $hourlyRate, $plan, $trialDays, $timezone,
        ): array {
            $user = $signedIn ?? $this->provisioning->provisionPlayer([
                'name' => $ownerName,
                'email' => $ownerEmail,
                'password' => $ownerPassword,
            ]);

            if ($newAccount) {
                // The emailed code is proof of ownership, so the account starts verified.
                $user->forceFill(['email_verified_at' => now()])->save();
                $verification->forceFill(['used_at' => now()])->save();
            }

            $tenant = Tenant::create([
                'name' => $name,
                'slug' => $slug,
                'business_email' => $contactEmail !== '' ? $contactEmail : $user->email,
                'business_phone' => $phone,
                'address' => $address,
                'status' => 'active',
                'subscription_plan_id' => $plan?->id,
            ]);

            $ownerRole = Role::query()->where('name', 'owner')->firstOrFail();
            TenantUser::updateOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['role_id' => $ownerRole->id, 'status' => 'active'],
            );

            $facility = Facility::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'slug' => 'main',
                'phone' => $phone,
                'email' => $contactEmail !== '' ? $contactEmail : null,
                'address' => $address,
                'city' => $chain['city']->name,
                'state' => $chain['province']->name,
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
                'is_active' => true,
            ]);

            VenueProfile::create([
                'tenant_id' => $tenant->id,
                'barangay_id' => $barangayId,
                'tagline' => 'Pickleball courts in '.$chain['city']->name.'.',
                'description' => '',
                'rules' => '',
                'contact_phone' => $phone,
                'contact_email' => $contactEmail,
                'address' => $address,
                'city' => $chain['city']->name,
                'province' => $chain['province']->name,
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
                'amenities' => [],
                'timezone' => $timezone,
                'status' => 'pending_review',
                'submitted_at' => now(),
                'settings' => [
                    'allow_online_booking' => true,
                    'booking_payments' => [
                        'pay_at_court' => true,
                        'gcash' => null,
                        'maya' => null,
                        'require_prepayment' => false,
                    ],
                    'allow_guest_booking' => true,
                    'allow_partial_payments' => true,
                    'cancellation_policy' => 'Free cancellation up to 24 hours before your booking. After that, call us.',
                    'player_cancellation_hours' => 24,
                    'payment_instructions' => 'Pay at the front desk when you arrive, before you go on court.',
                ],
            ]);

            for ($n = 1; $n <= $courtCount; $n++) {
                $court = Court::create([
                    'facility_id' => $facility->id,
                    'tenant_id' => $tenant->id,
                    'name' => 'Court '.$n,
                    'court_type' => $indoorOutdoor,
                    'surface_type' => $indoorOutdoor === 'indoor' ? 'Sport tile' : 'Acrylic',
                    'hourly_rate' => (float) $hourlyRate,
                    'is_active' => true,
                ]);

                for ($weekday = 0; $weekday <= 6; $weekday++) {
                    CourtOperatingHour::create([
                        'court_id' => $court->id,
                        'day_of_week' => $weekday,
                        'open_time' => $opensAt.':00',
                        'close_time' => $closesAt.':00',
                        'is_closed' => false,
                    ]);
                }
            }

            if ($plan !== null) {
                TenantSubscription::create([
                    'tenant_id' => $tenant->id,
                    'subscription_plan_id' => $plan->id,
                    'status' => 'trialing',
                    'starts_at' => now(),
                    'trial_ends_at' => now()->addDays($trialDays),
                ]);
            }

            return ['user' => $user, 'tenant' => $tenant];
        });

        if ($newAccount) {
            Auth::guard('web')->login($result['user']);

            if ($request->hasSession()) {
                $request->session()->regenerate();
            }
        }

        return $this->successResponse(data: [
            'club_id' => (string) $result['tenant']->id,
            'club_slug' => $result['tenant']->slug,
            'club_name' => $result['tenant']->name,
            'status' => 'pending_review',
        ], code: Response::HTTP_CREATED);
    }

    /**
     * `venue_signups_open` defaults to open until an admin closes it.
     */
    private static function signupsOpen(): bool
    {
        return (bool) PlatformSetting::get('venue_signups_open', true);
    }

    private function invalidCode(string $message): JsonResponse
    {
        return $this->errorResponse(
            message: 'The given data was invalid.',
            errors: ['code' => [$message]],
            code: Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * The contract appends "-2" until the address is free.
     */
    private function uniqueSlug(string $name): string
    {
        $slug = Str::slug($name) ?: 'venue';

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug .= '-2';
        }

        return $slug;
    }
}
