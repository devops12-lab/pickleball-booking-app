<?php

namespace App\Support\Compat;

use App\Models\Club;
use App\Models\ClubMember;
use App\Models\ClubMemberEvent;
use App\Models\ClubMembershipPlan;
use App\Models\GeoLocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Club membership shapes from packages/api/src/open-play-types.ts.
 *
 * Only communities have members; a venue answers 404 for these paths. The
 * stored plan snapshot is what the member joined on, so editing a plan later
 * never rewrites their terms.
 */
class MembershipView
{
    /**
     * @return array<string, mixed>
     */
    public static function plan(ClubMembershipPlan $plan): array
    {
        return [
            'id' => (string) $plan->id,
            'organizer_type' => 'community',
            'organizer_id' => (string) $plan->club_id,
            'name' => (string) $plan->name,
            'description' => (string) ($plan->description ?? ''),
            'price' => Contract::money($plan->price),
            'currency' => Contract::CURRENCY,
            'duration_days' => $plan->duration_days === null ? null : (int) $plan->duration_days,
            'session_discount_percent' => (int) $plan->session_discount_percent,
            'perks' => self::perks($plan->perks),
            'recommended' => (bool) $plan->recommended,
            'is_active' => (bool) $plan->is_active,
        ];
    }

    /**
     * @return list<array{text: string, included: bool}>
     */
    public static function perks(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $out = [];

        foreach ($stored as $perk) {
            if (is_string($perk)) {
                $out[] = ['text' => $perk, 'included' => true];

                continue;
            }

            if (! is_array($perk) || trim((string) ($perk['text'] ?? '')) === '') {
                continue;
            }

            $out[] = [
                'text' => trim((string) $perk['text']),
                'included' => (bool) ($perk['included'] ?? true),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(ClubMember $member): array
    {
        $member->loadMissing(['plan', 'payments']);

        return [
            'id' => (string) $member->id,
            'status' => self::status($member),
            'role' => self::role((string) $member->role),
            'plan' => $member->plan === null ? null : [
                'id' => (string) $member->plan->id,
                'name' => (string) $member->plan->name,
            ],
            'starts_at' => $member->starts_at?->toIso8601String(),
            'expires_at' => $member->expires_at?->toIso8601String(),
            'payment' => self::payment($member->payment),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function member(ClubMember $member): array
    {
        $member->loadMissing(['user.playerProfile', 'plan', 'payments']);

        $paid = (float) $member->payments->sum('amount');
        $due = (float) $member->dues_due;
        $profile = $member->user?->playerProfile;
        $chain = $profile === null ? null : GeoLocation::chain($profile->barangay_id);

        return self::summary($member) + [
            'organizer_type' => 'community',
            'organizer_id' => (string) $member->club_id,
            'player' => [
                'id' => (string) $member->user_id,
                'name' => (string) ($member->user?->name ?? 'Player'),
                'home_city' => $chain === null ? null : $chain['city']->name,
                'skill_level' => (string) ($profile?->skill_level ?: 'intermediate'),
            ],
            'requested_at' => ($member->requested_at ?? $member->joined_at ?? $member->created_at ?? now())
                ->toIso8601String(),
            'dues' => [
                'amount_due' => Contract::money($due),
                'amount_paid' => Contract::money($paid),
                'status' => $due <= 0 ? 'not_required' : ($paid >= $due ? 'paid' : 'unpaid'),
            ],
            'sessions_attended' => self::sessionsAttended($member),
            'application' => is_array($member->application) ? $member->application : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(ClubMember $member): array
    {
        $member->loadMissing(['events.actor']);

        return self::member($member) + [
            'history' => $member->events
                ->sortByDesc(fn (ClubMemberEvent $event) => $event->occurred_at?->getTimestamp() * 1000 + $event->id)
                ->map(fn (ClubMemberEvent $event): array => self::event($event))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function event(ClubMemberEvent $event): array
    {
        $event->loadMissing('actor');

        return [
            'id' => (string) $event->id,
            'event_type' => (string) $event->event_type,
            'actor' => Contract::person($event->actor) ?? ['id' => '0', 'name' => 'System'],
            'reason' => $event->reason,
            'occurred_at' => $event->occurred_at->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>|null
     */
    public static function payment(?array $stored): ?array
    {
        if (! is_array($stored) || ! isset($stored['method'], $stored['status'])) {
            return null;
        }

        return [
            'method' => (string) $stored['method'],
            'status' => (string) $stored['status'],
            'amount' => Contract::money($stored['amount'] ?? 0),
            'receipt_url' => $stored['receipt_url'] ?? null,
            'reference_number' => $stored['reference_number'] ?? null,
            'submitted_at' => $stored['submitted_at'] ?? null,
            'rejection_reason' => $stored['rejection_reason'] ?? null,
        ];
    }

    public static function status(ClubMember $member): string
    {
        $status = match ((string) $member->status) {
            'active' => 'active',
            'pending' => 'pending',
            'suspended', 'banned' => 'suspended',
            'rejected' => 'rejected',
            default => 'active',
        };

        if ($status === 'active' && $member->expires_at !== null && $member->expires_at->isPast()) {
            return 'expired';
        }

        return $status;
    }

    public static function role(string $role): string
    {
        return match ($role) {
            'owner' => 'owner',
            'manager', 'organizer', 'admin' => 'admin',
            default => 'member',
        };
    }

    /**
     * Start the current period now, on the plan they joined. Mirrors the mock:
     * an open free join and a confirmed dues payment both land here.
     */
    public static function activate(ClubMember $member): void
    {
        $member->loadMissing(['plan', 'club']);
        $wasActive = (string) $member->status === 'active';

        $days = $member->plan?->duration_days;
        $member->forceFill([
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => $days === null ? null : now()->addDays((int) $days),
        ])->save();

        $club = $member->club;

        if (! $wasActive && $club !== null) {
            NotificationService::send(
                (int) $member->user_id,
                'clubs',
                'membership_active',
                "Welcome to {$club->name}",
                'Your membership is active. Member rates and members-only sessions are open to you.',
                "/clubs/community/{$club->slug}",
            );
        }
    }

    public static function log(
        ClubMember $member,
        string $type,
        ?int $actorId,
        ?string $reason = null,
        ?CarbonImmutable $at = null,
    ): ClubMemberEvent {
        return $member->events()->create([
            'event_type' => $type,
            'actor_id' => $actorId,
            'reason' => $reason,
            'occurred_at' => $at ?? now(),
        ]);
    }

    /**
     * Dues recorded for a member, net of nothing (dues are never refunded here).
     */
    public static function paid(ClubMember $member): float
    {
        return (float) $member->payments()->sum('amount');
    }

    private static function sessionsAttended(ClubMember $member): int
    {
        return DB::table('open_play_participants')
            ->join('open_play_sessions', 'open_play_sessions.id', '=', 'open_play_participants.open_play_session_id')
            ->where('open_play_participants.user_id', $member->user_id)
            ->whereIn('open_play_participants.status', ['checked_in', 'completed'])
            ->where('open_play_sessions.organizer_type', 'community')
            ->where('open_play_sessions.organizer_id', $member->club_id)
            ->distinct()
            ->count('open_play_participants.open_play_session_id');
    }
}
