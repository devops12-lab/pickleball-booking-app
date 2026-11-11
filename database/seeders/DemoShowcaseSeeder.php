<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Booking;
use App\Models\BookingReceipt;
use App\Models\BookingStatusHistory;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\ClubMemberEvent;
use App\Models\ClubMembershipPayment;
use App\Models\ClubMembershipPlan;
use App\Models\Court;
use App\Models\CourtOperatingHour;
use App\Models\CourtPriceRule;
use App\Models\Facility;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\MatchRatingChange;
use App\Models\OpenPlayParticipant;
use App\Models\OpenPlaySession;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\PlayerProfile;
use App\Models\PlayerStatistic;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\Tournament;
use App\Models\TournamentCategory;
use App\Models\TournamentParticipant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * A self-contained demo for a live walkthrough: one Cebu venue, its owner and
 * staff, ten ranked players, a community club, bookings, completed matches,
 * open play, and a tournament. Re-running it is safe; matches are only added
 * when the showcase venue has none yet.
 */
class DemoShowcaseSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'Password123!';

    private const VENUE_SLUG = 'lapu-lapu-pickleball-club';

    public function run(): void
    {
        DB::transaction(function (): void {
            $roles = Role::query()->pluck('id', 'name');
            $ownerRole = (int) $roles->get('owner');
            $staffRole = (int) ($roles->get('staff') ?? $ownerRole);

            if ($ownerRole === 0) {
                return;
            }

            $tenant = $this->createVenue($ownerRole, $staffRole);
            $owner = User::query()->where('email', 'owner@courtflex.test')->firstOrFail();
            $organizer = User::query()->where('email', 'organizer@courtflex.test')->firstOrFail();
            $courts = $this->createCourts($tenant);
            $players = $this->createPlayers();

            $this->createClubs($tenant, $owner, $organizer, $players);
            $this->createBookings($tenant, $owner, $courts, $players);

            if (! GameMatch::query()->where('venue_id', $tenant->id)->exists()) {
                $this->createMatches($tenant, $owner, $organizer, $courts, $players);
            }

            $this->createOpenPlay($tenant, $owner, $courts, $players);
            $this->createTournament($tenant, $organizer, $players);
            $this->createAnnouncements();
        });
    }

    private function createVenue(int $ownerRoleId, int $staffRoleId): Tenant
    {
        $plan = SubscriptionPlan::query()->where('slug', 'starter')->first();
        $barangay = 'b-072226005'; // Basak, City of Lapu-Lapu

        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => self::VENUE_SLUG],
            [
                'name' => 'Lapu-Lapu Pickleball Club',
                'business_email' => 'play@lapulapupickleball.test',
                'business_phone' => '0917 555 0210',
                'address' => 'Basak, City of Lapu-Lapu, Cebu',
                'status' => 'active',
                'subscription_plan_id' => $plan?->id,
            ],
        );

        $owner = User::query()->where('email', 'owner@courtflex.test')->firstOrFail();
        $staff = User::query()->where('email', 'staff@courtflex.test')->firstOrFail();

        TenantUser::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $owner->id],
            ['role_id' => $ownerRoleId, 'status' => 'active'],
        );
        TenantUser::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $staff->id],
            ['role_id' => $staffRoleId, 'status' => 'active'],
        );

        if ($plan !== null) {
            TenantSubscription::query()->updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'subscription_plan_id' => $plan->id,
                    'status' => 'trialing',
                    'starts_at' => now(),
                    'trial_ends_at' => now()->addDays(14),
                ],
            );
        }

        $tenant->venueProfile()->updateOrCreate([], [
            'barangay_id' => $barangay,
            'tagline' => 'Island pickleball, every night.',
            'description' => 'Four breeze-cooled courts, friendly round robins, and a pro shop a short walk from Mactan.',
            'rules' => 'Non-marking shoes only. Bookings are held for 15 minutes. Please keep music low after 21:00.',
            'cover_url' => null,
            'contact_phone' => '0917 555 0210',
            'contact_email' => 'play@lapulapupickleball.test',
            'address' => 'Basak, City of Lapu-Lapu, Cebu',
            'city' => 'City of Lapu-Lapu',
            'province' => 'Cebu',
            'latitude' => 10.3050,
            'longitude' => 123.9790,
            'amenities' => ['Parking', 'Lights', 'Showers', 'Restrooms', 'Pro shop', 'Paddle rental', 'Wi-Fi'],
            'timezone' => 'Asia/Manila',
            'status' => 'active',
            'settings' => [
                'allow_online_booking' => true,
                'allow_guest_booking' => true,
                'allow_walk_ins' => true,
                'booking_window_days' => 30,
                'player_cancellation_hours' => 12,
                'cancellation_policy' => 'Free cancellation up to 12 hours before your slot.',
                'payment_instructions' => 'Send the GCash or Maya receipt from the app so the front desk can confirm your slot.',
                'booking_payments' => [
                    'pay_at_court' => true,
                    'gcash' => [
                        'account_name' => 'Lapu-Lapu Pickleball Club',
                        'account_number' => '09175550210',
                        'qr_url' => null,
                    ],
                    'maya' => [
                        'account_name' => 'Lapu-Lapu Pickleball Club',
                        'account_number' => '09175550210',
                        'qr_url' => null,
                    ],
                    'require_prepayment' => false,
                ],
            ],
        ]);

        Facility::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'lapu-lapu-pickleball-courts'],
            [
                'name' => 'Lapu-Lapu Pickleball Courts',
                'description' => 'The island home of CourtFlex demo pickleball.',
                'phone' => '0917 555 0210',
                'email' => 'play@lapulapupickleball.test',
                'address' => 'Basak, City of Lapu-Lapu, Cebu',
                'city' => 'City of Lapu-Lapu',
                'state' => 'Cebu',
                'postal_code' => '6015',
                'latitude' => 10.3050,
                'longitude' => 123.9790,
                'is_active' => true,
            ],
        );

        return $tenant;
    }

    /**
     * @return array<string, Court>
     */
    private function createCourts(Tenant $tenant): array
    {
        $facility = Facility::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $specs = [
            ['key' => 'center', 'name' => 'Center Court', 'code' => 'LL-01', 'type' => 'indoor', 'surface' => 'cushioned', 'rate' => 500],
            ['key' => 'sunset', 'name' => 'Sunset Court', 'code' => 'LL-02', 'type' => 'outdoor', 'surface' => 'acrylic', 'rate' => 400],
            ['key' => 'north', 'name' => 'North Court', 'code' => 'LL-03', 'type' => 'indoor', 'surface' => 'acrylic', 'rate' => 450],
            ['key' => 'training', 'name' => 'Training Court', 'code' => 'LL-04', 'type' => 'outdoor', 'surface' => 'concrete', 'rate' => 300],
        ];

        $courts = [];

        foreach ($specs as $spec) {
            $court = Court::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $spec['name']],
                [
                    'facility_id' => $facility->id,
                    'court_code' => $spec['code'],
                    'court_type' => $spec['type'],
                    'surface_type' => $spec['surface'],
                    'hourly_rate' => $spec['rate'],
                    'is_active' => true,
                    'status' => 'active',
                ],
            );

            for ($day = 0; $day <= 6; $day++) {
                CourtOperatingHour::query()->updateOrCreate(
                    ['court_id' => $court->id, 'day_of_week' => $day],
                    ['open_time' => '06:00:00', 'close_time' => '23:00:00', 'is_closed' => false],
                );
            }

            CourtPriceRule::query()->updateOrCreate(
                ['court_id' => $court->id, 'day_of_week' => null, 'starts_at' => '18:00', 'ends_at' => '23:00'],
                ['price' => $spec['rate'] + 100],
            );

            $courts[$spec['key']] = $court;
        }

        return $courts;
    }

    /**
     * @return array<int, array{user: User, rating: int, style: string, city: string}>
     */
    private function createPlayers(): array
    {
        $specs = [
            ['email' => 'player@courtflex.test', 'name' => 'Pete Player', 'display' => 'Pete Player', 'skill' => 'advanced', 'rating' => 1475, 'style' => 'male', 'barangay' => 'b-072226005', 'bio' => 'Weeknight doubles regular. Loves a long rally and a cold buko after.'],
            ['email' => 'mia.santos@courtflex.test', 'name' => 'Mia Santos', 'display' => 'Mia Santos', 'skill' => 'advanced', 'rating' => 1510, 'style' => 'female', 'barangay' => 'b-072226021', 'bio' => 'Left-side doubles player. Always up for a third game.'],
            ['email' => 'liam.cruz@courtflex.test', 'name' => 'Liam Cruz', 'display' => 'Liam Cruz', 'skill' => 'advanced', 'rating' => 1470, 'style' => 'male', 'barangay' => 'b-072226024', 'bio' => 'Mactan local, big serve, louder cheer.'],
            ['email' => 'sofia.navarro@courtflex.test', 'name' => 'Sofia Navarro', 'display' => 'Sofia Navarro', 'skill' => 'advanced', 'rating' => 1440, 'style' => 'female', 'barangay' => 'b-072226016', 'bio' => 'Former tennis player finding a new home at the kitchen line.'],
            ['email' => 'bea.reyes@courtflex.test', 'name' => 'Bea Reyes', 'display' => 'Bea Reyes', 'skill' => 'intermediate', 'rating' => 1420, 'style' => 'female', 'barangay' => 'b-072226015', 'bio' => 'Dinking enthusiast and club organizer.'],
            ['email' => 'organizer@courtflex.test', 'name' => 'Oscar Organizer', 'display' => 'Oscar Organizer', 'skill' => 'intermediate', 'rating' => 1390, 'style' => 'male', 'barangay' => 'b-072226018', 'bio' => 'Runs the Sunday round robin and keeps the queue moving.'],
            ['email' => 'noah.garcia@courtflex.test', 'name' => 'Noah Garcia', 'display' => 'Noah Garcia', 'skill' => 'intermediate', 'rating' => 1370, 'style' => 'male', 'barangay' => 'b-072226006', 'bio' => 'Learning the soft game one drop shot at a time.'],
            ['email' => 'ava.mendoza@courtflex.test', 'name' => 'Ava Mendoza', 'display' => 'Ava Mendoza', 'skill' => 'intermediate', 'rating' => 1350, 'style' => 'female', 'barangay' => 'b-072226002', 'bio' => 'Morning player, coffee first, third-shot drop second.'],
            ['email' => 'ethan.lim@courtflex.test', 'name' => 'Ethan Lim', 'display' => 'Ethan Lim', 'skill' => 'novice', 'rating' => 1320, 'style' => 'male', 'barangay' => 'b-072226003', 'bio' => 'New to the sport and already addicted.'],
            ['email' => 'marco.villanueva@courtflex.test', 'name' => 'Marco Villanueva', 'display' => 'Marco Villanueva', 'skill' => 'beginner', 'rating' => 1280, 'style' => 'male', 'barangay' => 'b-072226007', 'bio' => 'Here for the exercise and the post-game stories.'],
        ];

        $players = [];

        foreach ($specs as $spec) {
            $user = User::query()->updateOrCreate(
                ['email' => $spec['email']],
                [
                    'name' => $spec['name'],
                    'phone' => '0917'.str_pad((string) (7000000 + count($players)), 7, '0', STR_PAD_LEFT),
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'account_status' => 'active',
                    'email_verified_at' => now(),
                ],
            );

            PlayerProfile::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'display_name' => $spec['display'],
                    'skill_level' => $spec['skill'],
                    'bio' => $spec['bio'],
                    'play_formats' => ['doubles'],
                    'preferred_times' => ['morning', 'evening'],
                    'visibility' => 'public',
                    'avatar_style' => $spec['style'],
                    'location_verified' => true,
                    'barangay_id' => $spec['barangay'],
                ],
            );

            PlayerStatistic::query()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'current_rating' => $spec['rating'],
                    'singles_rating' => $spec['rating'],
                    'doubles_rating' => $spec['rating'],
                ],
            );

            $players[] = [
                'user' => $user,
                'rating' => (int) $spec['rating'],
                'style' => $spec['style'],
                'city' => 'City of Lapu-Lapu',
            ];
        }

        return $players;
    }

    /**
     * @param  array<int, array{user: User, rating: int, style: string, city: string}>  $players
     */
    private function createClubs(Tenant $tenant, User $owner, User $organizer, array $players): void
    {
        $club = Club::query()->updateOrCreate(
            ['slug' => 'cebu-dink-society'],
            [
                'tenant_id' => null,
                'home_venue_id' => $tenant->id,
                'creator_id' => $organizer->id,
                'name' => 'Cebu Dink Society',
                'tagline' => 'Fast hands, soft game, open invite.',
                'description' => 'A community club for Lapu-Lapu and Mactan players who want organized doubles without the pressure.',
                'privacy' => 'public',
                'status' => 'active',
                'city' => 'City of Lapu-Lapu',
                'province' => 'Cebu',
                'max_members' => 120,
                'join_policy' => 'open',
                'contact_email' => 'hello@cebudink.test',
                'contact_phone' => '0917 555 0220',
                'rules' => 'Respect every level. Rotate partners. Cheer the good shots.',
                'payment_options' => [
                    'gcash' => ['account_name' => 'Cebu Dink Society', 'account_number' => '09175550220'],
                    'maya' => ['account_name' => 'Cebu Dink Society', 'account_number' => '09175550220'],
                ],
            ],
        );

        $this->addClubPlans($club, $owner);
        $this->joinClub($club, $players, $owner);
    }

    private function addClubPlans(Club $club, User $owner): void
    {
        $plans = [
            ['name' => 'Social Circle', 'price' => 0, 'days' => 30, 'discount' => 0, 'recommended' => false, 'perks' => ['Weekly socials', 'Club chat']],
            ['name' => 'Unlimited Rally', 'price' => 500, 'days' => 30, 'discount' => 10, 'recommended' => true, 'perks' => ['All open play sessions', '10% off court bookings', 'Priority queue']],
            ['name' => 'Island Pass', 'price' => 1200, 'days' => 90, 'discount' => 15, 'recommended' => false, 'perks' => ['Three months of play', '15% off court bookings', 'Guest passes']],
        ];

        foreach ($plans as $plan) {
            ClubMembershipPlan::query()->updateOrCreate(
                ['club_id' => $club->id, 'name' => $plan['name']],
                [
                    'description' => $plan['perks'][0],
                    'price' => $plan['price'],
                    'currency' => 'PHP',
                    'duration_days' => $plan['days'],
                    'session_discount_percent' => $plan['discount'],
                    'perks' => $plan['perks'],
                    'recommended' => $plan['recommended'],
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * @param  array<int, array{user: User, rating: int, style: string, city: string}>  $players
     */
    private function joinClub(Club $club, array $players, User $owner): void
    {
        $plan = ClubMembershipPlan::query()
            ->where('club_id', $club->id)
            ->where('name', 'Unlimited Rally')
            ->first();

        $members = array_slice($players, 0, 7);

        foreach ($members as $index => $player) {
            $member = ClubMember::query()->updateOrCreate(
                ['club_id' => $club->id, 'user_id' => $player['user']->id],
                [
                    'role' => $index === 0 ? 'manager' : 'member',
                    'status' => 'active',
                    'plan_id' => $plan?->id,
                    'joined_at' => now()->subMonths(2),
                    'starts_at' => now()->subMonth(),
                    'expires_at' => now()->addMonth(),
                    'dues_due' => 0,
                ],
            );

            ClubMemberEvent::query()->firstOrCreate(
                ['club_member_id' => $member->id, 'event_type' => 'joined'],
                ['actor_id' => $owner->id, 'occurred_at' => now()->subMonths(2)],
            );

            if (($plan?->price ?? 0) > 0) {
                ClubMembershipPayment::query()->firstOrCreate(
                    ['club_member_id' => $member->id, 'description' => 'Monthly club dues'],
                    [
                        'club_id' => $club->id,
                        'payer_id' => $player['user']->id,
                        'amount' => $plan->price,
                        'method' => 'gcash',
                        'reference_number' => 'DEMO-CLUB-'.$club->id.'-'.$member->id,
                        'received_by' => $owner->id,
                        'received_at' => now()->subDays(5),
                    ],
                );
            }
        }
    }

    /**
     * @param  array<string, Court>  $courts
     * @param  array<int, array{user: User, rating: int, style: string, city: string}>  $players
     */
    private function createBookings(Tenant $tenant, User $owner, array $courts, array $players): void
    {
        $byEmail = collect($players)->keyBy(fn (array $player): string => (string) $player['user']->email);
        $courtList = array_values($courts);
        $now = CarbonImmutable::now('Asia/Manila');

        $specs = [
            ['ref' => 'DEMO-LL-1001', 'email' => 'player@courtflex.test', 'court' => 0, 'days' => 1, 'hour' => 18, 'status' => 'confirmed', 'method' => 'pay_at_court', 'source' => 'player_app'],
            ['ref' => 'DEMO-LL-1002', 'email' => 'mia.santos@courtflex.test', 'court' => 1, 'days' => 1, 'hour' => 19, 'status' => 'confirmed', 'method' => 'pay_at_court', 'source' => 'player_app'],
            ['ref' => 'DEMO-LL-1003', 'email' => 'liam.cruz@courtflex.test', 'court' => 2, 'days' => 2, 'hour' => 17, 'status' => 'pending', 'method' => 'gcash', 'source' => 'player_app'],
            ['ref' => 'DEMO-LL-1004', 'email' => 'bea.reyes@courtflex.test', 'court' => 3, 'days' => 2, 'hour' => 19, 'status' => 'confirmed', 'method' => 'pay_at_court', 'source' => 'player_app'],
            ['ref' => 'DEMO-LL-1005', 'email' => 'player@courtflex.test', 'court' => 1, 'days' => -2, 'hour' => 18, 'status' => 'completed', 'method' => 'gcash', 'source' => 'player_app'],
            ['ref' => 'DEMO-LL-1006', 'email' => 'sofia.navarro@courtflex.test', 'court' => 2, 'days' => -3, 'hour' => 19, 'status' => 'completed', 'method' => 'maya', 'source' => 'player_app'],
            ['ref' => 'DEMO-LL-1007', 'email' => 'noah.garcia@courtflex.test', 'court' => 0, 'days' => -5, 'hour' => 17, 'status' => 'completed', 'method' => 'gcash', 'source' => 'player_app'],
            ['ref' => 'DEMO-LL-1008', 'email' => 'marco.villanueva@courtflex.test', 'court' => 0, 'days' => 4, 'hour' => 20, 'status' => 'cancelled', 'method' => 'pay_at_court', 'source' => 'player_app'],
        ];

        foreach ($specs as $spec) {
            $player = $byEmail->get($spec['email']);
            $court = $courtList[$spec['court']];
            $starts = $now->addDays($spec['days'])->setTime($spec['hour'], 0)->setTimezone('UTC');
            $ends = $starts->addHour();
            $amount = (float) $court->hourly_rate;

            $booking = Booking::query()->updateOrCreate(
                ['booking_reference' => $spec['ref']],
                [
                    'tenant_id' => $tenant->id,
                    'court_id' => $court->id,
                    'user_id' => $player['user']->id,
                    'created_by' => $player['user']->id,
                    'starts_at' => $starts->toDateTimeString(),
                    'ends_at' => $ends->toDateTimeString(),
                    'total_amount' => $amount,
                    'status' => $spec['status'],
                    'source' => $spec['source'],
                    'payment_method' => $spec['method'],
                    'hold_expires_at' => $spec['status'] === 'pending' ? now()->addHours(2) : null,
                    'notes' => $spec['status'] === 'cancelled' ? 'Player changed plans.' : null,
                ],
            );

            BookingStatusHistory::query()->updateOrCreate(
                ['booking_id' => $booking->id, 'from_status' => null],
                [
                    'changed_by' => $player['user']->id,
                    'to_status' => $spec['status'],
                    'reason' => $spec['status'] === 'pending' ? 'GCash receipt sent for review' : 'Demo booking',
                ],
            );

            if ($spec['status'] === 'pending') {
                BookingReceipt::query()->firstOrCreate(
                    ['booking_id' => $booking->id, 'reference_number' => 'DEMO-GCASH-1003'],
                    [
                        'image_url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=',
                        'status' => 'in_review',
                    ],
                );
            }

            if (in_array($spec['status'], ['completed'], true)) {
                $payment = Payment::query()->firstOrCreate(
                    ['transaction_reference' => 'DEMO-PAY-'.$spec['ref']],
                    [
                        'tenant_id' => $tenant->id,
                        'booking_id' => $booking->id,
                        'user_id' => $player['user']->id,
                        'amount' => $amount,
                        'currency' => 'PHP',
                        'payment_method' => $spec['method'],
                        'status' => 'verified',
                    ],
                );

                PaymentVerification::query()->firstOrCreate(
                    ['payment_id' => $payment->id],
                    ['verified_by' => $owner->id, 'status' => 'approved', 'notes' => 'Demo payment confirmed.', 'verified_at' => $ends],
                );

                if ($payment->wasRecentlyCreated) {
                    $payment->forceFill(['created_at' => $ends, 'updated_at' => $ends])->save();
                }
            }
        }
    }

    /**
     * @param  array<string, Court>  $courts
     * @param  array<int, array{user: User, rating: int, style: string, city: string}>  $players
     */
    private function createMatches(Tenant $tenant, User $owner, User $organizer, array $courts, array $players): void
    {
        $ids = array_map(fn (array $player): int => (int) $player['user']->id, $players);
        $ratings = [];
        $stats = [];

        foreach ($players as $player) {
            $id = (int) $player['user']->id;
            $ratings[$id] = (int) $player['rating'];
            $stats[$id] = PlayerStatistic::query()->updateOrCreate(
                ['user_id' => $id],
                [
                    'current_rating' => $ratings[$id],
                    'singles_rating' => $ratings[$id],
                    'doubles_rating' => $ratings[$id],
                    'matches_played' => 0,
                    'matches_won' => 0,
                    'matches_lost' => 0,
                    'singles_matches' => 0,
                    'singles_wins' => 0,
                    'singles_losses' => 0,
                    'doubles_matches' => 0,
                    'doubles_wins' => 0,
                    'doubles_losses' => 0,
                ],
            );
        }

        $courtList = array_values($courts);
        $now = CarbonImmutable::now('Asia/Manila');
        $total = count($ids);

        for ($round = 0; $round < 18; $round++) {
            $picked = [];
            for ($offset = 0; $offset < 4; $offset++) {
                $picked[] = $ids[($round + $offset) % $total];
            }

            $teamA = [$picked[0], $picked[1]];
            $teamB = [$picked[2], $picked[3]];
            $winnerSide = ($ratings[$teamA[0]] + $ratings[$teamA[1]]) >= ($ratings[$teamB[0]] + $ratings[$teamB[1]]) ? 0 : 1;
            $loserScore = 5 + ($round % 5);
            $score = $winnerSide === 0
                ? '11-'.$loserScore.', 11-'.($loserScore + 1)
                : $loserScore.'-11, '.($loserScore + 1).'-11';

            $starts = $now->subDays(19 - $round)->setTime(18, 0)->setTimezone('UTC');
            $ends = $starts->addHour();
            $court = $courtList[$round % count($courtList)];

            $match = GameMatch::query()->create([
                'match_type' => 'doubles',
                'category' => 'competitive',
                'format' => ['target' => 11, 'win_by' => 2, 'best_of' => 1, 'scoring' => 'side_out'],
                'status' => 'completed',
                'venue_id' => $tenant->id,
                'court_id' => $court->id,
                'first_serving' => $round % 2,
                'scorer_id' => $organizer->id,
                'created_by' => $organizer->id,
                'score' => $score,
                'winner_side' => $winnerSide,
                'started_at' => $starts,
                'ended_at' => $ends,
                'submitted_by' => $organizer->id,
                'submitted_at' => $ends,
                'verified_by' => $owner->id,
                'verified_at' => $ends,
            ]);
            $match->forceFill(['created_at' => $starts, 'updated_at' => $ends])->save();

            foreach ([[0, $teamA], [1, $teamB]] as [$side, $team]) {
                foreach ($team as $position => $userId) {
                    MatchPlayer::query()->create([
                        'match_id' => $match->id,
                        'user_id' => $userId,
                        'side' => $side,
                        'position' => $position,
                    ]);
                }
            }

            foreach ([0 => $teamA, 1 => $teamB] as $side => $team) {
                $won = $side === $winnerSide;
                $change = $won ? 12 : -12;

                foreach ($team as $userId) {
                    $old = $ratings[$userId];
                    $new = $old + $change;

                    $rating = MatchRatingChange::query()->create([
                        'match_id' => $match->id,
                        'player_id' => $userId,
                        'type' => 'doubles',
                        'kind' => 'match',
                        'old_rating' => $old,
                        'change' => $change,
                        'new_rating' => $new,
                        'version' => 'elo-v1',
                    ]);
                    $rating->forceFill(['created_at' => $ends, 'updated_at' => $ends])->save();

                    $ratings[$userId] = $new;
                    $stat = $stats[$userId];
                    $stat->current_rating = $new;
                    $stat->doubles_rating = $new;
                    $stat->matches_played = (int) $stat->matches_played + 1;
                    $stat->matches_won = (int) $stat->matches_won + ($won ? 1 : 0);
                    $stat->matches_lost = (int) $stat->matches_lost + ($won ? 0 : 1);
                    $stat->doubles_matches = (int) $stat->doubles_matches + 1;
                    $stat->doubles_wins = (int) $stat->doubles_wins + ($won ? 1 : 0);
                    $stat->doubles_losses = (int) $stat->doubles_losses + ($won ? 0 : 1);
                    $stat->save();
                }
            }
        }

        $this->createUpcomingMatch($tenant, $organizer, $courtList[0], $ids);
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function createUpcomingMatch(Tenant $tenant, User $organizer, Court $court, array $ids): void
    {
        $starts = CarbonImmutable::now('Asia/Manila')->addDay()->setTime(20, 0)->setTimezone('UTC');

        GameMatch::query()->create([
            'match_type' => 'doubles',
            'category' => 'competitive',
            'format' => ['target' => 11, 'win_by' => 2, 'best_of' => 1, 'scoring' => 'side_out'],
            'status' => 'scheduled',
            'venue_id' => $tenant->id,
            'court_id' => $court->id,
            'first_serving' => 0,
            'scorer_id' => $organizer->id,
            'created_by' => $organizer->id,
            'started_at' => $starts,
        ])->players()->createMany([
            ['user_id' => $ids[0], 'side' => 0, 'position' => 0],
            ['user_id' => $ids[1], 'side' => 0, 'position' => 1],
            ['user_id' => $ids[2], 'side' => 1, 'position' => 0],
            ['user_id' => $ids[3], 'side' => 1, 'position' => 1],
        ]);

        $awaitingStarts = CarbonImmutable::now('Asia/Manila')->subDay()->setTime(19, 0)->setTimezone('UTC');
        $awaitingEnds = $awaitingStarts->addHour();

        $awaiting = GameMatch::query()->create([
            'match_type' => 'doubles',
            'category' => 'competitive',
            'format' => ['target' => 11, 'win_by' => 2, 'best_of' => 1, 'scoring' => 'side_out'],
            'status' => 'awaiting_confirmation',
            'venue_id' => $tenant->id,
            'court_id' => $court->id,
            'first_serving' => 1,
            'scorer_id' => $organizer->id,
            'created_by' => $organizer->id,
            'score' => '11-9, 9-11, 11-8',
            'winner_side' => 0,
            'started_at' => $awaitingStarts,
            'ended_at' => $awaitingEnds,
            'submitted_by' => $ids[2],
            'submitted_at' => $awaitingEnds,
        ]);
        $awaiting->players()->createMany([
            ['user_id' => $ids[0], 'side' => 0, 'position' => 0],
            ['user_id' => $ids[1], 'side' => 0, 'position' => 1],
            ['user_id' => $ids[2], 'side' => 1, 'position' => 0],
            ['user_id' => $ids[3], 'side' => 1, 'position' => 1],
        ]);
    }

    /**
     * @param  array<string, Court>  $courts
     * @param  array<int, array{user: User, rating: int, style: string, city: string}>  $players
     */
    private function createOpenPlay(Tenant $tenant, User $owner, array $courts, array $players): void
    {
        $courtList = array_values($courts);
        $now = CarbonImmutable::now('Asia/Manila');

        $sessions = [
            ['title' => 'Sunset Doubles Social', 'days' => 1, 'start' => 18, 'end' => 21, 'fee' => 200, 'max' => 16, 'skill' => 'all', 'description' => 'Four courts, rotating partners, and a beginner-friendly first hour.'],
            ['title' => 'Saturday Beginner Clinic', 'days' => 3, 'start' => 9, 'end' => 11, 'fee' => 150, 'max' => 12, 'skill' => 'beginner', 'description' => 'Learn the serve, return, and third-shot drop with Coach Mia.'],
            ['title' => 'Friday Night Rally', 'days' => 5, 'start' => 19, 'end' => 22, 'fee' => 250, 'max' => 20, 'skill' => 'intermediate', 'description' => 'Fast-paced open play for intermediate and advanced players.'],
        ];

        $memberEmails = array_slice(array_map(fn (array $p): string => (string) $p['user']->email, $players), 0, 7);

        foreach ($sessions as $index => $spec) {
            $starts = $now->addDays($spec['days'])->setTime($spec['start'], 0)->setTimezone('UTC');
            $ends = $now->addDays($spec['days'])->setTime($spec['end'], 0)->setTimezone('UTC');
            $primaryCourt = $courtList[($index + 2) % count($courtList)];
            $secondaryCourt = $courtList[($index + 3) % count($courtList)];

            $session = OpenPlaySession::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'title' => $spec['title']],
                [
                    'organizer_type' => 'venue',
                    'organizer_id' => $tenant->id,
                    'host_id' => $owner->id,
                    'court_id' => $primaryCourt->id,
                    'description' => $spec['description'],
                    'session_type' => 'open_play',
                    'skill_level' => $spec['skill'],
                    'starts_at' => $starts,
                    'ends_at' => $ends,
                    'registration_deadline' => $starts->subDay(),
                    'max_participants' => $spec['max'],
                    'fee' => $spec['fee'],
                    'member_fee' => $spec['fee'] - 50,
                    'members_only' => false,
                    'status' => 'published',
                    'court_fee' => 500,
                    'queue_version' => 1,
                ],
            );

            $session->courts()->sync([
                $primaryCourt->id,
                $secondaryCourt->id,
            ]);

            foreach (array_slice($players, 0, $index === 0 ? 8 : 5) as $position => $player) {
                $isMember = in_array($player['user']->email, $memberEmails, true);
                $paid = $position < 4;

                OpenPlayParticipant::query()->updateOrCreate(
                    ['open_play_session_id' => $session->id, 'user_id' => $player['user']->id],
                    [
                        'status' => 'registered',
                        'fee_due' => $isMember ? $spec['fee'] - 50 : $spec['fee'],
                        'fee_paid' => $paid ? ($isMember ? $spec['fee'] - 50 : $spec['fee']) : 0,
                        'fee_status' => $paid ? 'paid' : 'unpaid',
                        'is_member' => $isMember,
                        'check_in_status' => 'registered',
                    ],
                );
            }
        }
    }

    /**
     * @param  array<int, array{user: User, rating: int, style: string, city: string}>  $players
     */
    private function createTournament(Tenant $tenant, User $organizer, array $players): void
    {
        $starts = CarbonImmutable::now('Asia/Manila')->addWeeks(3)->setTime(8, 0);
        $ends = $starts->addDays(2)->setTime(18, 0);

        $tournament = Tournament::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'lapu-lapu-open-2026'],
            [
                'venue_id' => $tenant->id,
                'organizer_id' => $organizer->id,
                'host_type' => 'venue',
                'host_id' => $tenant->id,
                'name' => 'Lapu-Lapu Open 2026',
                'description' => 'A weekend of doubles on the island. Open and mixed brackets, with a community lunch on Saturday.',
                'format' => 'single_elimination',
                'match_type' => 'doubles',
                'court_ids' => Court::query()->where('tenant_id', $tenant->id)->pluck('id')->all(),
                'game' => ['target' => 11, 'win_by' => 2, 'best_of' => 1, 'scoring' => 'side_out'],
                'starts_at' => $starts,
                'ends_at' => $ends,
                'registration_deadline' => $starts->subWeek(),
                'entry_fee' => 600,
                'max_teams' => 16,
                'capacity' => 16,
                'pools' => 2,
                'status' => 'registration_open',
            ],
        );

        $open = TournamentCategory::query()->firstOrCreate(
            ['tournament_id' => $tournament->id, 'name' => 'Open Doubles'],
            ['division' => 'doubles', 'skill_level' => '3.5+'],
        );
        $mixed = TournamentCategory::query()->firstOrCreate(
            ['tournament_id' => $tournament->id, 'name' => 'Mixed Doubles'],
            ['division' => 'mixed', 'skill_level' => '3.0+'],
        );

        $pairs = [
            [$players[0], $players[2], $open],
            [$players[1], $players[3], $open],
            [$players[4], $players[6], $open],
            [$players[7], $players[8], $open],
            [$players[0], $players[1], $mixed],
            [$players[2], $players[4], $mixed],
            [$players[3], $players[7], $mixed],
            [$players[5], $players[9], $mixed],
        ];

        foreach ($pairs as $index => [$one, $two, $category]) {
            TournamentParticipant::query()->updateOrCreate(
                [
                    'tournament_id' => $tournament->id,
                    'user_id' => $one['user']->id,
                    'partner_id' => $two['user']->id,
                ],
                [
                    'tournament_category_id' => $category->id,
                    'team_name' => $this->firstName($one['user']->name).' / '.$this->firstName($two['user']->name),
                    'seed' => $index % 4 + 1,
                    'rating' => (int) round(($one['rating'] + $two['rating']) / 2),
                    'status' => 'confirmed',
                ],
            );
        }
    }

    private function createAnnouncements(): void
    {
        $admin = User::query()->where('email', 'admin@courtflex.test')->first();

        Announcement::query()->firstOrCreate(
            ['title' => 'Lapu-Lapu Open registration is open'],
            [
                'message' => 'Open and mixed doubles brackets are open. Register in the app before the deadline.',
                'audience' => 'players',
                'tone' => 'info',
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addWeeks(3),
                'created_by' => $admin?->id,
            ],
        );

        Announcement::query()->firstOrCreate(
            ['title' => 'New demo venue is live'],
            [
                'message' => 'Lapu-Lapu Pickleball Club now has four bookable courts, open play, and a community club.',
                'audience' => 'everyone',
                'tone' => 'info',
                'starts_at' => now()->subDays(2),
                'ends_at' => now()->addWeeks(2),
                'created_by' => $admin?->id,
            ],
        );
    }

    private function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return $parts[0] ?? $name;
    }
}
