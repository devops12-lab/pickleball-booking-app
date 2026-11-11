<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * The demo venue has no map pin, so it can't appear in player search. These are
 * the coordinates of the arena in the demo data (Austin, TX) and the amenities
 * its description mentions.
 */
class DemoVenuePinSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'metro-pickleball-club')->first();

        if ($tenant === null || $tenant->venueProfile === null) {
            return;
        }

        $profile = $tenant->venueProfile;

        // The demo venue takes guest bookings and shows demo wallet details, so the
        // guest flow in the SPA can be walked end to end on a fresh seed.
        $settings = $profile->settings;
        $settings['allow_guest_booking'] = $settings['allow_guest_booking'] ?? true;
        $settings['booking_payments']['gcash'] = $settings['booking_payments']['gcash']
            ?? ['account_name' => $tenant->name, 'account_number' => '09171234567', 'qr_url' => null];
        $settings['booking_payments']['maya'] = $settings['booking_payments']['maya']
            ?? ['account_name' => $tenant->name, 'account_number' => '09171234567', 'qr_url' => null];

        $profile->fill([
            'settings' => $settings,
            'latitude' => $profile->latitude ?? 30.2672,
            'longitude' => $profile->longitude ?? -97.7431,
            'amenities' => $profile->amenities ?? [
                'Parking',
                'Lights',
                'Restrooms',
                'Covered court',
                'Air-conditioned',
                'Paddle rental',
                'Showers',
                'Lockers',
                'Pro shop',
            ],
        ]);

        $profile->save();
    }
}
