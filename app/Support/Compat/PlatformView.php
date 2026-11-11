<?php

namespace App\Support\Compat;

use App\Models\Announcement;
use App\Models\PlatformSetting;
use Carbon\CarbonImmutable;

/**
 * The public and Super Admin platform contract in
 * packages/api/src/admin-types.ts and platform-hooks.ts.
 */
class PlatformView
{
    private const DEFAULTS = [
        'trial_days' => 14,
        'venue_signups_open' => true,
        'support_email' => 'support@courtflex.test',
        'support_phone' => '0917 555 0100',
    ];

    /**
     * @return array{trial_days: int, venue_signups_open: bool, support_email: string, support_phone: string}
     */
    public static function settings(): array
    {
        return [
            'trial_days' => (int) PlatformSetting::get('trial_days', self::DEFAULTS['trial_days']),
            'venue_signups_open' => (bool) PlatformSetting::get('venue_signups_open', self::DEFAULTS['venue_signups_open']),
            'support_email' => (string) PlatformSetting::get('support_email', self::DEFAULTS['support_email']),
            'support_phone' => (string) PlatformSetting::get('support_phone', self::DEFAULTS['support_phone']),
        ];
    }

    public static function supportLine(): string
    {
        $settings = self::settings();

        return 'Contact CourtFlex support at '.$settings['support_email'].' or '.$settings['support_phone'].'.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function announcement(Announcement $announcement): array
    {
        $creator = $announcement->creator;

        return [
            'id' => (string) $announcement->id,
            'title' => (string) $announcement->title,
            'message' => (string) $announcement->message,
            'audience' => (string) $announcement->audience,
            'tone' => (string) $announcement->tone,
            'starts_at' => $announcement->starts_at->toIso8601String(),
            'ends_at' => $announcement->ends_at?->toIso8601String(),
            'created_by' => [
                'id' => (string) ($creator?->id ?? '0'),
                'name' => (string) ($creator?->name ?? 'Super Admin'),
            ],
            'created_at' => $announcement->created_at->toIso8601String(),
            'status' => self::announcementStatus($announcement),
        ];
    }

    public static function announcementStatus(Announcement $announcement, ?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();

        if ($announcement->ends_at !== null && $announcement->ends_at->lessThanOrEqualTo($now)) {
            return 'ended';
        }

        if ($announcement->starts_at->greaterThan($now)) {
            return 'scheduled';
        }

        return 'live';
    }
}
