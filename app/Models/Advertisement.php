<?php

namespace App\Models;

use App\Enums\AdvertisementPlacement;
use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementTargetAudience;
use App\Enums\AdvertisementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Advertisement extends Model
{
    protected $fillable = [
        'title',
        'description',
        'cta_label',
        'destination_url',
        'type',
        'placement',
        'target_audience',
        'media_path',
        'media_type',
        'thumbnail_path',
        'start_date',
        'end_date',
        'priority',
        'status',
        'impressions_count',
        'clicks_count',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => AdvertisementType::class,
            'placement' => AdvertisementPlacement::class,
            'target_audience' => AdvertisementTargetAudience::class,
            'status' => AdvertisementStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'impressions_count' => 'integer',
            'clicks_count' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Published ads within their active date range.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AdvertisementStatus::Published)
            ->where('start_date', '<=', now()->toDateString())
            ->where('end_date', '>=', now()->toDateString());
    }

    public function scopeForPlacement(Builder $query, string $placement): Builder
    {
        return $query->where('placement', $placement);
    }

    public function scopeForAudience(Builder $query, string $audience): Builder
    {
        return $query->where(function (Builder $q) use ($audience): void {
            $q->where('target_audience', 'all')
                ->orWhere('target_audience', $audience);
        });
    }
}
