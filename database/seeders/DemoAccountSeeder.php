<?php

namespace Database\Seeders;

use App\Models\Club;
use App\Models\ClubMember;
use App\Models\PlayerStatistic;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Auth\AccountProvisioningService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seed one demo account per role for local development and staging.
 *
 * This seeder is NOT included in DatabaseSeeder and must never run in production.
 * Invoke it explicitly:
 *
 *     php artisan db:seed --class=DemoAccountSeeder
 */
class DemoAccountSeeder extends Seeder
{
    private const PASSWORD = 'Password123!';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('This seeder must not run in production.');

            return;
        }

        $provisioning = app(AccountProvisioningService::class);

        // ── 1. System Admin ──────────────────────────────────────────────
        $admin = $this->createAdmin();

        // ── 2. Facility Owner (creates a Tenant) ────────────────────────
        $ownerResult = $this->createOwner($provisioning);

        // ── 3. Facility Staff (assigned to the owner's tenant) ──────────
        $this->createStaff($ownerResult['tenant']);

        // ── 4. Player ────────────────────────────────────────────────────
        $this->createPlayer($provisioning);

        // ── 5. Club Organizer (player + club + organizer membership) ────
        $this->createOrganizer($provisioning, $ownerResult['tenant']);

        // ── Summary ──────────────────────────────────────────────────────
        $this->command?->newLine();
        $this->command?->info('Demo accounts seeded successfully. All passwords: '.self::PASSWORD);
        $this->command?->newLine();
        $this->command?->table(
            ['Role', 'Name', 'Email'],
            [
                ['System Admin', 'Demo Administrator', 'admin@courtflex.test'],
                ['Owner', 'Demo Facility Owner', 'owner@courtflex.test'],
                ['Staff', 'Demo Staff Member', 'staff@courtflex.test'],
                ['Player', 'Demo Player', 'player@courtflex.test'],
                ['Organizer', 'Demo Club Organizer', 'organizer@courtflex.test'],
            ]
        );
    }

    /**
     * Create the system administrator linked to the platform-admin tenant.
     */
    private function createAdmin(): User
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@courtflex.test'],
            [
                'name' => 'Demo Administrator',
                'password' => Hash::make(self::PASSWORD),
                'account_status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        PlayerStatistic::firstOrCreate(
            ['user_id' => $admin->id],
            ['current_rating' => 1500.00]
        );

        $adminRole = Role::where('name', 'system_admin')->firstOrFail();
        $systemTenant = Tenant::where('slug', 'platform-admin')->firstOrFail();

        TenantUser::firstOrCreate(
            ['tenant_id' => $systemTenant->id, 'user_id' => $admin->id],
            ['role_id' => $adminRole->id, 'status' => 'active']
        );

        return $admin;
    }

    /**
     * Create a facility owner with a demo venue.
     *
     * @return array{user: User, tenant: Tenant}
     */
    private function createOwner(AccountProvisioningService $provisioning): array
    {
        $existing = User::where('email', 'owner@courtflex.test')->first();

        if ($existing) {
            $ownerRole = Role::where('name', 'owner')->first();
            $tenant = $ownerRole
                ? TenantUser::where('user_id', $existing->id)->where('role_id', $ownerRole->id)->first()?->tenant
                : null;

            if ($tenant) {
                return ['user' => $existing, 'tenant' => $tenant];
            }
        }

        return $provisioning->provisionOwner(
            [
                'name' => 'Demo Facility Owner',
                'email' => 'owner@courtflex.test',
                'password' => self::PASSWORD,
            ],
            [
                'name' => 'Demo Pickleball Venue',
                'business_email' => 'owner@courtflex.test',
                'business_phone' => '+63-912-345-6789',
                'address' => '123 Pickleball Street, Manila',
            ]
        );
    }

    /**
     * Create a staff member assigned to the given tenant.
     */
    private function createStaff(Tenant $tenant): User
    {
        $staff = User::firstOrCreate(
            ['email' => 'staff@courtflex.test'],
            [
                'name' => 'Demo Staff Member',
                'password' => Hash::make(self::PASSWORD),
                'account_status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        PlayerStatistic::firstOrCreate(
            ['user_id' => $staff->id],
            ['current_rating' => 1200.00]
        );

        $staffRole = Role::where('name', 'staff')->firstOrFail();

        TenantUser::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $staff->id],
            ['role_id' => $staffRole->id, 'status' => 'active']
        );

        return $staff;
    }

    /**
     * Create a player with profile and statistics.
     */
    private function createPlayer(AccountProvisioningService $provisioning): User
    {
        $existing = User::where('email', 'player@courtflex.test')->first();

        if ($existing) {
            return $existing;
        }

        return $provisioning->provisionPlayer([
            'name' => 'Demo Player',
            'email' => 'player@courtflex.test',
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * Create a club organizer: provision as player first, then create a club
     * and assign organizer membership.
     */
    private function createOrganizer(AccountProvisioningService $provisioning, Tenant $tenant): User
    {
        $existing = User::where('email', 'organizer@courtflex.test')->first();

        if (! $existing) {
            $existing = $provisioning->provisionPlayer([
                'name' => 'Demo Club Organizer',
                'email' => 'organizer@courtflex.test',
                'password' => self::PASSWORD,
            ]);
        }

        $club = Club::firstOrCreate(
            ['slug' => 'demo-pickleball-club'],
            [
                'tenant_id' => $tenant->id,
                'creator_id' => $existing->id,
                'name' => 'Demo Pickleball Club',
                'privacy' => 'public',
                'status' => 'active',
            ]
        );

        ClubMember::firstOrCreate(
            ['club_id' => $club->id, 'user_id' => $existing->id],
            ['role' => 'organizer', 'status' => 'active', 'joined_at' => now()]
        );

        return $existing;
    }
}
