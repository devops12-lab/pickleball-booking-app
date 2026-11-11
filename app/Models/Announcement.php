<?php

namespace App\Models;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementStatus;
use App\Enums\AnnouncementType;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property string $title
 * @property string $body
 * @property AnnouncementType $type
 * @property AnnouncementAudience $audience
 * @property AnnouncementStatus $status
 * @property Carbon|null $publish_at
 * @property Carbon|null $published_at
 * @property Carbon|null $expires_at
 * @property bool $is_pinned
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin \Eloquent
 */
class Announcement extends Model
{
    use BelongsToTenant;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AnnouncementType::class,
            'audience' => AnnouncementAudience::class,
            'status' => AnnouncementStatus::class,
            'publish_at' => 'datetime',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_pinned' => 'boolean',
        ];
    }

    /**
     * Administrator or facility owner that created the announcement.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Read receipts recorded for the announcement.
     */
    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    /**
     * Limit the query to published, non-expired announcements.
     */
    public function scopePublished(Builder $query): void
    {
        $query
            ->where('status', AnnouncementStatus::Published->value)
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Limit the query to announcements targeted at the given audience value.
     */
    public function scopeForAudience(Builder $query, string $audience): void
    {
        $query->whereIn('audience', [AnnouncementAudience::All->value, $audience]);
    }

    /**
     * Limit the query to platform-wide and the given tenant announcements.
     */
    public function scopeVisibleToTenant(Builder $query, ?int $tenantId): void
    {
        $query->where(function (Builder $query) use ($tenantId): void {
            $query->whereNull('tenant_id');

            if ($tenantId !== null) {
                $query->orWhere('tenant_id', $tenantId);
            }
        });
    }

    /**
     * Order pinned announcements first, newest first afterwards.
     */
    public function scopePinnedFirst(Builder $query): void
    {
        $query->orderByDesc('is_pinned')->orderByDesc('published_at')->orderByDesc('id');
    }
}
