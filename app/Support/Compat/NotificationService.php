<?php

namespace App\Support\Compat;

use App\Models\AppNotification;
use App\Models\NotificationPreference;
use App\Models\PlayerProfile;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * In-app notifications and per-category preferences
 * (packages/api/src/notification-types.ts).
 *
 * Only players with a profile receive them, matching the SPA's mock. Email is
 * a stored preference for a later transport; nothing is sent from here.
 */
class NotificationService
{
    /** @var list<string> */
    public const CATEGORIES = ['matches', 'tournaments', 'bookings', 'clubs', 'friends'];

    /**
     * Missing preference rows read as these defaults, exactly as the mock does.
     *
     * @return array<string, array{in_app: bool, email: bool}>
     */
    public static function defaultPreferences(): array
    {
        return [
            'matches' => ['in_app' => true, 'email' => false],
            'tournaments' => ['in_app' => true, 'email' => false],
            'bookings' => ['in_app' => true, 'email' => true],
            'clubs' => ['in_app' => true, 'email' => false],
            'friends' => ['in_app' => true, 'email' => false],
        ];
    }

    /**
     * @return array<string, array{in_app: bool, email: bool}>
     */
    public static function preferences(int $userId): array
    {
        $stored = NotificationPreference::query()
            ->where('user_id', $userId)
            ->get()
            ->keyBy('category');
        $defaults = self::defaultPreferences();
        $out = [];

        foreach (self::CATEGORIES as $category) {
            $row = $stored->get($category);
            $out[$category] = [
                'in_app' => $row === null ? $defaults[$category]['in_app'] : (bool) $row->in_app,
                'email' => $row === null ? $defaults[$category]['email'] : (bool) $row->email,
            ];
        }

        return $out;
    }

    /**
     * Create one notification if the recipient is a player and has that
     * category switched on in the app.
     */
    public static function send(
        ?int $userId,
        string $category,
        string $type,
        string $title,
        string $body,
        ?string $link = null,
        ?CarbonInterface $at = null,
    ): ?AppNotification {
        if ($userId === null || ! in_array($category, self::CATEGORIES, true)) {
            return null;
        }

        if (! PlayerProfile::query()->where('user_id', $userId)->exists()) {
            return null;
        }

        if (! (self::preferences($userId)[$category]['in_app'] ?? false)) {
            return null;
        }

        return AppNotification::query()->create([
            'id' => (string) Str::orderedUuid(),
            'user_id' => $userId,
            'category' => $category,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'read_at' => null,
            'created_at' => $at ?? now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(AppNotification $notification): array
    {
        return [
            'id' => (string) $notification->id,
            'category' => (string) $notification->category,
            'type' => (string) $notification->type,
            'title' => (string) $notification->title,
            'body' => (string) $notification->body,
            'link' => $notification->link,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at->toIso8601String(),
        ];
    }

    public static function firstName(string $name): string
    {
        $name = trim($name);
        $parts = preg_split('/\s+/', $name);
        $first = $parts[0] ?? $name;

        return $first !== '' ? $first : $name;
    }
}
