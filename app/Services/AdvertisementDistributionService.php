<?php

namespace App\Services;

use App\Models\Advertisement;
use App\Models\AdvertisementClick;
use App\Models\AdvertisementImpression;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AdvertisementDistributionService
{
    /**
     * Get active ads for a user and placement.
     * Cached for 60 seconds per placement+audience key.
     */
    public function getAdsForUser(?User $user, string $placement, int $limit = 5): Collection
    {
        $audience = $this->audienceForUser($user);
        $cacheKey = "ads:{$placement}:{$audience}";

        return Cache::remember($cacheKey, 60, function () use ($placement, $audience, $limit): Collection {
            return Advertisement::active()
                ->forPlacement($placement)
                ->forAudience($audience)
                ->orderByDesc('priority')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Record an ad impression. Deduplicates: 1 per user+ad+placement per hour.
     * Returns true if a new impression was recorded.
     */
    public function recordImpression(Advertisement $ad, ?User $user, string $placement, Request $request): bool
    {
        $ipHash = hash('sha256', $request->ip() ?? 'unknown');
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        $recent = AdvertisementImpression::where('advertisement_id', $ad->id)
            ->where('placement', $placement)
            ->where('viewed_at', '>=', now()->subHour())
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->when(! $user, fn ($q) => $q->where('ip_hash', $ipHash))
            ->exists();

        if ($recent) {
            return false;
        }

        AdvertisementImpression::create([
            'advertisement_id' => $ad->id,
            'user_id' => $user?->id,
            'session_id' => $sessionId,
            'ip_hash' => $ipHash,
            'viewed_at' => now(),
            'placement' => $placement,
        ]);

        $ad->increment('impressions_count');

        return true;
    }

    /**
     * Record an ad click. Deduplicates: 1 per user+ad per hour.
     * Returns true if a new click was recorded.
     */
    public function recordClick(Advertisement $ad, ?User $user, Request $request): bool
    {
        $ipHash = hash('sha256', $request->ip() ?? 'unknown');
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        $recent = AdvertisementClick::where('advertisement_id', $ad->id)
            ->where('clicked_at', '>=', now()->subHour())
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->when(! $user, fn ($q) => $q->where('ip_hash', $ipHash))
            ->exists();

        if ($recent) {
            return false;
        }

        AdvertisementClick::create([
            'advertisement_id' => $ad->id,
            'user_id' => $user?->id,
            'session_id' => $sessionId,
            'ip_hash' => $ipHash,
            'clicked_at' => now(),
            'destination_url' => $ad->destination_url,
        ]);

        $ad->increment('clicks_count');

        return true;
    }

    private function audienceForUser(?User $user): string
    {
        if (! $user) {
            return 'all';
        }

        if ($user->isSystemAdmin()) {
            return 'all';
        }

        if ($user->hasRole('owner')) {
            return 'owners';
        }

        if ($user->hasRole('staff')) {
            return 'staff';
        }

        if ($user->hasRole('club_manager')) {
            return 'organizers';
        }

        return 'players';
    }
}
