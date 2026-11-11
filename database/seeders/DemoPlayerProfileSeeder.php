<?php

namespace Database\Seeders;

use App\Models\PlayerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The demo accounts exist as players in the mock data (they have ratings), so
 * they get a player profile too. Without one the SPA sends them to onboarding
 * every time they sign in.
 */
class DemoPlayerProfileSeeder extends Seeder
{
    public function run(): void
    {
        $players = [
            ['email' => 'owner@courtflex.test', 'display_name' => 'Olivia Owner', 'skill_level' => 'intermediate'],
            ['email' => 'staff@courtflex.test', 'display_name' => 'Sam Staff', 'skill_level' => 'beginner'],
            ['email' => 'player@courtflex.test', 'display_name' => 'Pete Player', 'skill_level' => 'advanced'],
            ['email' => 'organizer@courtflex.test', 'display_name' => 'Oscar Organizer', 'skill_level' => 'intermediate'],
        ];

        foreach ($players as $data) {
            $user = User::query()->where('email', $data['email'])->first();

            if ($user === null) {
                continue;
            }

            PlayerProfile::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'display_name' => $data['display_name'],
                    'skill_level' => $data['skill_level'],
                    'bio' => '',
                    'play_formats' => ['doubles'],
                    'preferred_times' => ['evening'],
                    'visibility' => 'public',
                    'location_verified' => false,
                ]
            );
        }
    }
}
