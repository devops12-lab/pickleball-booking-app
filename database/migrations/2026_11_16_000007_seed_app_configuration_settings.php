<?php

use App\Models\PlatformSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * @var list<array{key: string, value: string|null, type: string, is_public: bool}>
     */
    private array $settings = [
        ['key' => 'app_name', 'value' => 'CourtFlex', 'type' => 'string', 'is_public' => true],
        ['key' => 'app_description', 'value' => 'The premier pickleball court booking platform', 'type' => 'string', 'is_public' => true],
        ['key' => 'app_logo', 'value' => null, 'type' => 'string', 'is_public' => true],
        ['key' => 'app_favicon', 'value' => null, 'type' => 'string', 'is_public' => true],
        ['key' => 'official_website', 'value' => null, 'type' => 'string', 'is_public' => true],
        ['key' => 'business_address', 'value' => null, 'type' => 'string', 'is_public' => true],
        ['key' => 'platform_socials', 'value' => '[]', 'type' => 'json', 'is_public' => true],
        ['key' => 'ad_inquiry_email', 'value' => null, 'type' => 'string', 'is_public' => true],
        ['key' => 'ad_contact_phone', 'value' => null, 'type' => 'string', 'is_public' => true],
        ['key' => 'ad_instructions', 'value' => null, 'type' => 'string', 'is_public' => true],
        ['key' => 'default_timezone', 'value' => 'Asia/Manila', 'type' => 'string', 'is_public' => false],
        ['key' => 'default_currency', 'value' => 'PHP', 'type' => 'string', 'is_public' => false],
        ['key' => 'maintenance_mode', 'value' => '0', 'type' => 'boolean', 'is_public' => true],
        ['key' => 'public_booking_enabled', 'value' => '1', 'type' => 'boolean', 'is_public' => true],
        ['key' => 'registration_enabled', 'value' => '1', 'type' => 'boolean', 'is_public' => true],
    ];

    public function up(): void
    {
        foreach ($this->settings as $setting) {
            PlatformSetting::firstOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'type' => $setting['type'],
                    'is_public' => $setting['is_public'],
                ],
            );
        }
    }

    public function down(): void
    {
        $keys = array_column($this->settings, 'key');

        PlatformSetting::whereIn('key', $keys)->delete();
    }
};
