<?php

namespace Database\Seeders;

use App\Models\Club;
use App\Models\ClubMember;
use App\Models\Court;
use App\Models\CourtOperatingHour;
use App\Models\Facility;
use App\Models\OpenPlaySession;
use App\Models\PlayerStatistic;
use App\Models\Role;
use App\Models\StaffInvitation;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    /**
     * Seed comprehensive demo data for Postman and manual testing.
     */
    public function run(): void
    {
        $ownerRole = Role::where('name', 'owner')->first();
        $staffRole = Role::where('name', 'staff')->first();
        $playerRole = Role::where('name', 'player')->first();
        $clubManagerRole = Role::where('name', 'club_manager')->first();

        // 1. Tenant: Metro Pickleball Club
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'metro-pickleball-club'],
            [
                'name' => 'Metro Pickleball Club',
                'business_email' => 'contact@metropickleball.test',
                'business_phone' => '+1-512-555-0100',
                'address' => '1000 Congress Ave, Suite 200',
                'status' => 'active',
            ]
        );

        // 2. Facility Owner: owner@courtflex.test
        $owner = User::firstOrCreate(
            ['email' => 'owner@courtflex.test'],
            [
                'name' => 'Olivia Owner',
                'password' => Hash::make('Password123!'),
                'account_status' => 'active',
                'email_verified_at' => now(),
            ]
        );
        TenantUser::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $owner->id],
            ['role_id' => $ownerRole->id, 'status' => 'active']
        );
        PlayerStatistic::firstOrCreate(['user_id' => $owner->id], ['current_rating' => 1350.00]);

        // 3. Facility Staff: staff@courtflex.test
        $staff = User::firstOrCreate(
            ['email' => 'staff@courtflex.test'],
            [
                'name' => 'Sam Staff',
                'password' => Hash::make('Password123!'),
                'account_status' => 'active',
                'email_verified_at' => now(),
            ]
        );
        TenantUser::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $staff->id],
            ['role_id' => $staffRole->id, 'status' => 'active']
        );
        PlayerStatistic::firstOrCreate(['user_id' => $staff->id], ['current_rating' => 1250.00]);

        // 4. Global Player: player@courtflex.test
        $player = User::firstOrCreate(
            ['email' => 'player@courtflex.test'],
            [
                'name' => 'Pete Player',
                'password' => Hash::make('Password123!'),
                'account_status' => 'active',
                'email_verified_at' => now(),
            ]
        );
        PlayerStatistic::firstOrCreate(['user_id' => $player->id], [
            'current_rating' => 1485.50,
            'matches_played' => 18,
            'matches_won' => 12,
            'matches_lost' => 6,
        ]);

        // 5. Club Organizer: organizer@courtflex.test
        $organizer = User::firstOrCreate(
            ['email' => 'organizer@courtflex.test'],
            [
                'name' => 'Oscar Organizer',
                'password' => Hash::make('Password123!'),
                'account_status' => 'active',
                'email_verified_at' => now(),
            ]
        );
        PlayerStatistic::firstOrCreate(['user_id' => $organizer->id], ['current_rating' => 1400.00]);

        // 6. Demo Facility & Courts
        $facility = Facility::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'metro-arena-austin'],
            [
                'name' => 'Metro Arena Austin',
                'description' => 'Premier 12-court indoor and outdoor pickleball facility with pro shop and lounge.',
                'phone' => '+1-512-555-0199',
                'email' => 'austin@metropickleball.test',
                'address' => '400 Pickleball Pkwy',
                'city' => 'Austin',
                'state' => 'TX',
                'postal_code' => '78701',
                'is_active' => true,
            ]
        );

        $courts = [
            ['name' => 'Court 1 (Championship Indoor)', 'court_type' => 'indoor', 'surface_type' => 'cushioned', 'hourly_rate' => 600.00],
            ['name' => 'Court 2 (Standard Indoor)', 'court_type' => 'indoor', 'surface_type' => 'acrylic', 'hourly_rate' => 450.00],
            ['name' => 'Court 3 (Outdoor Covered)', 'court_type' => 'outdoor', 'surface_type' => 'concrete', 'hourly_rate' => 350.00],
        ];

        $firstCourt = null;
        foreach ($courts as $courtData) {
            $court = Court::firstOrCreate(
                ['facility_id' => $facility->id, 'name' => $courtData['name']],
                [
                    'tenant_id' => $tenant->id,
                    'court_type' => $courtData['court_type'],
                    'surface_type' => $courtData['surface_type'],
                    'hourly_rate' => $courtData['hourly_rate'],
                    'is_active' => true,
                ]
            );

            if (! $firstCourt) {
                $firstCourt = $court;
            }

            for ($day = 0; $day <= 6; $day++) {
                CourtOperatingHour::firstOrCreate(
                    ['court_id' => $court->id, 'day_of_week' => $day],
                    [
                        'open_time' => '06:00:00',
                        'close_time' => '23:00:00',
                        'is_closed' => false,
                    ]
                );
            }
        }

        // 7. Demo Club
        $club = Club::firstOrCreate(
            ['slug' => 'austin-dinking-masters'],
            [
                'tenant_id' => $tenant->id,
                'creator_id' => $organizer->id,
                'name' => 'Austin Dinking Masters',
                'description' => 'Social and competitive pickleball club for intermediate and advanced players.',
                'privacy' => 'public',
                'max_members' => 50,
            ]
        );

        ClubMember::firstOrCreate(
            ['club_id' => $club->id, 'user_id' => $organizer->id],
            ['role' => 'manager', 'status' => 'active']
        );

        ClubMember::firstOrCreate(
            ['club_id' => $club->id, 'user_id' => $player->id],
            ['role' => 'member', 'status' => 'active']
        );

        // 8. Demo Open Play Session
        if ($firstCourt) {
            OpenPlaySession::firstOrCreate(
                ['tenant_id' => $tenant->id, 'court_id' => $firstCourt->id, 'title' => 'Friday Night Dink & Social'],
                [
                    'description' => 'Casual mixed open-play session with round-robin rotations.',
                    'starts_at' => now()->addDays(2)->setTime(18, 0, 0),
                    'ends_at' => now()->addDays(2)->setTime(21, 0, 0),
                    'skill_level_min' => 3.0,
                    'skill_level_max' => 4.5,
                    'max_participants' => 16,
                    'fee' => 150.00,
                    'status' => 'scheduled',
                ]
            );
        }

        // 9. Demo Tournament
        Tournament::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'austin-open-pickleball-championship-2026'],
            [
                'organizer_id' => $organizer->id,
                'name' => 'Austin Open Championship 2026',
                'description' => 'Annual statewide tournament featuring singles, doubles, and mixed brackets.',
                'starts_at' => now()->addWeeks(2)->setTime(9, 0, 0),
                'ends_at' => now()->addWeeks(2)->addDays(2)->setTime(18, 0, 0),
                'registration_deadline' => now()->addWeeks(1)->setTime(23, 59, 59),
                'entry_fee' => 500.00,
                'max_teams' => 32,
                'status' => 'registration_open',
            ]
        );

        // 10. Demo Staff Invitation Token (for testing staff invitation acceptance in Postman)
        StaffInvitation::firstOrCreate(
            ['token' => 'demo-staff-invitation-token-12345'],
            [
                'tenant_id' => $tenant->id,
                'email' => 'newstaff.demo@courtflex.test',
                'role_id' => $staffRole->id,
                'invited_by' => $owner->id,
                'status' => 'pending',
                'expires_at' => now()->addDays(14),
            ]
        );
    }
}
