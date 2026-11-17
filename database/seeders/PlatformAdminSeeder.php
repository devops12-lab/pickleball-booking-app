<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class PlatformAdminSeeder extends Seeder
{
    /**
     * Seed the system tenant and subscription plans.
     *
     * Admin accounts are created via `php artisan admin:create`.
     */
    public function run(): void
    {
        // System tenant for platform-wide operations
        Tenant::firstOrCreate(
            ['slug' => 'platform-admin'],
            [
                'name' => 'CourtFlex Platform System',
                'business_email' => 'admin@courtflex.test',
                'status' => 'active',
            ]
        );

        // Seed subscription plans
        SubscriptionPlan::firstOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'description' => 'Get started with basic court management at no cost.',
                'price' => 0.00,
                'billing_interval' => 'monthly',
                'max_courts' => 2,
                'max_staff' => 1,
                'features' => ['court_booking', 'basic_reporting'],
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        SubscriptionPlan::firstOrCreate(
            ['slug' => 'starter'],
            [
                'name' => 'Starter',
                'description' => 'For boutique pickleball venues with up to 4 courts.',
                'price' => 1499.00,
                'billing_interval' => 'monthly',
                'max_courts' => 4,
                'max_staff' => 3,
                'features' => ['court_booking', 'payment_verification', 'basic_reporting'],
                'is_active' => true,
                'sort_order' => 2,
            ]
        );

        SubscriptionPlan::firstOrCreate(
            ['slug' => 'professional'],
            [
                'name' => 'Professional',
                'description' => 'For growing venues with tournaments, open play, and advanced analytics.',
                'price' => 2999.00,
                'billing_interval' => 'monthly',
                'max_courts' => 10,
                'max_staff' => 8,
                'features' => ['court_booking', 'payment_verification', 'tournaments', 'open_play', 'analytics', 'basic_reporting'],
                'is_active' => true,
                'sort_order' => 3,
            ]
        );

        SubscriptionPlan::firstOrCreate(
            ['slug' => 'business'],
            [
                'name' => 'Business',
                'description' => 'For high-capacity venues with unlimited courts, club management, and full platform access.',
                'price' => 4999.00,
                'billing_interval' => 'monthly',
                'max_courts' => 50,
                'max_staff' => 25,
                'features' => ['court_booking', 'payment_verification', 'tournaments', 'open_play', 'club_management', 'analytics', 'basic_reporting', 'priority_support'],
                'is_active' => true,
                'sort_order' => 4,
            ]
        );
    }
}
