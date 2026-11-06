<?php

namespace Database\Seeders;

use App\Models\PlayerStatistic;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PlatformAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminRole = Role::where('name', 'system_admin')->first();

        $admin = User::firstOrCreate(
            ['email' => 'admin@courtflex.test'],
            [
                'name' => 'CourtFlex Administrator',
                'password' => Hash::make('Password123!'),
                'account_status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        PlayerStatistic::firstOrCreate(
            ['user_id' => $admin->id],
            ['current_rating' => 1500.00]
        );

        // System tenant for platform-wide operations
        $systemTenant = Tenant::firstOrCreate(
            ['slug' => 'platform-admin'],
            [
                'name' => 'CourtFlex Platform System',
                'business_email' => 'admin@courtflex.test',
                'status' => 'active',
            ]
        );

        TenantUser::firstOrCreate(
            [
                'tenant_id' => $systemTenant->id,
                'user_id' => $admin->id,
            ],
            [
                'role_id' => $adminRole->id,
                'status' => 'active',
            ]
        );

        // Seed initial subscription plans
        SubscriptionPlan::firstOrCreate(
            ['slug' => 'starter'],
            [
                'name' => 'Starter Facility',
                'description' => 'For boutique pickleball venues with up to 4 courts.',
                'price' => 1499.00,
                'billing_interval' => 'monthly',
                'max_courts' => 4,
                'max_staff' => 3,
                'features' => ['court_booking', 'payment_verification', 'basic_reporting'],
                'is_active' => true,
            ]
        );

        SubscriptionPlan::firstOrCreate(
            ['slug' => 'pro'],
            [
                'name' => 'Pro Club & Arena',
                'description' => 'For high-capacity venues with unlimited courts, tournaments, and open-play.',
                'price' => 3999.00,
                'billing_interval' => 'monthly',
                'max_courts' => 20,
                'max_staff' => 15,
                'features' => ['court_booking', 'payment_verification', 'tournaments', 'open_play', 'club_management', 'analytics'],
                'is_active' => true,
            ]
        );
    }
}
