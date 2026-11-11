<?php

use App\Models\VenueProfile;
use App\Support\Compat\Contract;

test('club settings normalize null text policies to strings', function (): void {
    $profile = new VenueProfile;
    $profile->settings = [
        'cancellation_policy' => null,
        'payment_instructions' => null,
    ];

    $settings = Contract::settings($profile);

    expect($settings['cancellation_policy'])->toBe('')
        ->and($settings['payment_instructions'])->toBe('');
});
