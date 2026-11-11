<?php

namespace App\Services;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Booking;
use App\Models\Club;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Announcement lifecycle and visibility business logic.
 *
 * Platform-wide announcements (tenant_id = null) are managed by system
 * administrators; tenant announcements are managed by facility owners and
 * are always scoped through TenantContextService on the HTTP layer.
 */
class AnnouncementService
{
    public function __construct(
        protected AuditLogService $auditLog
    ) {}

    /**
     * Create a draft, scheduled or immediately published announcement.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user, ?int $tenantId = null): Announcement
    {
        $announcement = new Announcement($data);

        $announcement->tenant_id = $tenantId;
        $announcement->created_by = $user->id;
        $announcement->status = $this->initialStatus($announcement->publish_at);

        if ($announcement->status === AnnouncementStatus::Published) {
            $announcement->published_at = $announcement->publish_at ?? Carbon::now();
        }

        $announcement->save();

        $this->audit($announcement, $user, 'announcement_created', null, $this->snapshot($announcement), $tenantId);

        return $announcement;
    }

    /**
     * Update an announcement that has not been archived.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Announcement $announcement, array $data, User $user): Announcement
    {
        if (! $announcement->status->isEditable()) {
            throw new InvalidArgumentException('Archived announcements cannot be edited.');
        }

        $old = $this->snapshot($announcement);

        $wasPublished = $announcement->status === AnnouncementStatus::Published;

        $announcement->fill($data);

        if (! $wasPublished) {
            $announcement->status = $this->initialStatus($announcement->publish_at);

            if ($announcement->status === AnnouncementStatus::Published) {
                $announcement->published_at = $announcement->publish_at ?? Carbon::now();
            } else {
                $announcement->published_at = null;
            }
        }

        $announcement->save();

        $this->audit($announcement, $user, 'announcement_updated', $old, $this->snapshot($announcement), $announcement->tenant_id);

        return $announcement;
    }

    /**
     * Publish a draft or scheduled announcement now.
     */
    public function publish(Announcement $announcement, User $user): Announcement
    {
        if ($announcement->status === AnnouncementStatus::Published) {
            return $announcement;
        }

        if ($announcement->status === AnnouncementStatus::Archived) {
            throw new InvalidArgumentException('Archived announcements cannot be published again.');
        }

        $old = $this->snapshot($announcement);

        $announcement->forceFill([
            'status' => AnnouncementStatus::Published,
            'published_at' => Carbon::now(),
        ])->save();

        $this->audit($announcement, $user, 'announcement_published', $old, $this->snapshot($announcement), $announcement->tenant_id);

        return $announcement;
    }

    /**
     * Archive an announcement so it is no longer visible to its audience.
     */
    public function archive(Announcement $announcement, User $user): Announcement
    {
        if ($announcement->status === AnnouncementStatus::Archived) {
            return $announcement;
        }

        $old = $this->snapshot($announcement);

        $announcement->forceFill([
            'status' => AnnouncementStatus::Archived,
        ])->save();

        $this->audit($announcement, $user, 'announcement_archived', $old, $this->snapshot($announcement), $announcement->tenant_id);

        return $announcement;
    }

    /**
     * Delete an announcement together with its read receipts.
     */
    public function delete(Announcement $announcement, User $user): void
    {
        $old = $this->snapshot($announcement);
        $tenantId = $announcement->tenant_id;

        $announcement->delete();

        $this->audit(null, $user, 'announcement_deleted', $old, null, $tenantId);
    }

    /**
     * Publish every scheduled announcement whose publish time has passed.
     *
     * @return int The number of announcements published.
     */
    public function publishDue(): int
    {
        $due = Announcement::query()
            ->where('status', AnnouncementStatus::Scheduled->value)
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now())
            ->get();

        foreach ($due as $announcement) {
            $announcement->forceFill([
                'status' => AnnouncementStatus::Published,
                'published_at' => $announcement->publish_at,
            ])->save();
        }

        return $due->count();
    }

    /**
     * Paginated announcement feed visible to a player.
     *
     * Players see platform-wide announcements plus announcements from every
     * tenant where they have a booking or an active club membership.
     *
     * @return LengthAwarePaginator<Announcement>
     */
    public function feedForPlayer(User $user, int $perPage = 15)
    {
        return Announcement::query()
            ->where(function (Builder $query) use ($user): void {
                $query->whereNull('tenant_id')
                    ->orWhereIn('tenant_id', $this->tenantIdsForUser($user));
            })
            ->published()
            ->forAudience(AnnouncementAudience::Players->value)
            ->with(['creator', 'reads' => fn ($query) => $query->where('user_id', $user->id)])
            ->withCount('reads')
            ->pinnedFirst()
            ->paginate($perPage);
    }

    /**
     * Determine whether the player can read the given announcement.
     */
    public function canPlayerView(User $user, Announcement $announcement): bool
    {
        if (! $announcement->status->isVisible()) {
            return false;
        }

        if ($announcement->expires_at !== null && $announcement->expires_at->isPast()) {
            return false;
        }

        if (! $announcement->audience->includes('player')) {
            return false;
        }

        if ($announcement->tenant_id === null) {
            return true;
        }

        return $this->tenantIdsForUser($user)->contains($announcement->tenant_id);
    }

    /**
     * Mark the announcement as read for the user (idempotent).
     */
    public function markRead(User $user, Announcement $announcement): AnnouncementRead
    {
        return AnnouncementRead::firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $user->id],
            ['read_at' => now()]
        );
    }

    /**
     * Number of visible announcements the player has not read yet.
     */
    public function unreadCount(User $user): int
    {
        return Announcement::query()
            ->published()
            ->forAudience(AnnouncementAudience::Players->value)
            ->where(function ($query) use ($user): void {
                $query->whereNull('tenant_id')
                    ->orWhereIn('tenant_id', $this->tenantIdsForUser($user));
            })
            ->whereDoesntHave('reads', fn ($query) => $query->where('user_id', $user->id))
            ->count();
    }

    /**
     * Distinct tenant ids the user is connected to through bookings and clubs.
     *
     * @return Collection<int, int>
     */
    protected function tenantIdsForUser(User $user): Collection
    {
        $bookingTenants = Booking::query()
            ->where('user_id', $user->id)
            ->select('tenant_id')
            ->distinct()
            ->pluck('tenant_id');

        $clubTenants = Club::query()
            ->whereHas('members', fn ($query) => $query->where('user_id', $user->id))
            ->whereNotNull('tenant_id')
            ->select('tenant_id')
            ->distinct()
            ->pluck('tenant_id');

        return $bookingTenants->merge($clubTenants)->unique()->values();
    }

    /**
     * Derive the initial status from the requested publish time.
     */
    protected function initialStatus(?Carbon $publishAt): AnnouncementStatus
    {
        if ($publishAt === null) {
            return AnnouncementStatus::Draft;
        }

        return $publishAt->isFuture()
            ? AnnouncementStatus::Scheduled
            : AnnouncementStatus::Published;
    }

    /**
     * Record an administrative audit entry; audit failures never break the flow.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    protected function audit(?Announcement $announcement, User $user, string $action, ?array $old, ?array $new, ?int $tenantId): void
    {
        try {
            $this->auditLog->log($action, $announcement, $tenantId, $user, $old, $new);
        } catch (Throwable) {
            // Audit logging must never prevent the announcement operation.
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(Announcement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'tenant_id' => $announcement->tenant_id,
            'title' => $announcement->title,
            'type' => $announcement->type->value,
            'audience' => $announcement->audience->value,
            'status' => $announcement->status->value,
            'publish_at' => $announcement->publish_at?->toIso8601String(),
            'published_at' => $announcement->published_at?->toIso8601String(),
            'expires_at' => $announcement->expires_at?->toIso8601String(),
            'is_pinned' => $announcement->is_pinned,
        ];
    }
}
