<?php

namespace App\Http\Resources;

use App\Models\Advertisement;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Advertisement
 */
class AdvertisementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $upload = app(FileUploadService::class);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'cta_label' => $this->cta_label,
            'destination_url' => $this->destination_url,
            'type' => $this->type,
            'placement' => $this->placement,
            'target_audience' => $this->target_audience,
            'media_url' => $this->when($this->media_path !== null, fn () => $upload->url($this->media_path)),
            'media_type' => $this->media_type,
            'thumbnail_url' => $this->when($this->thumbnail_path !== null, fn () => $upload->url($this->thumbnail_path)),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'priority' => $this->priority,
            'status' => $this->status,
            'impressions_count' => $this->impressions_count,
            'clicks_count' => $this->clicks_count,
            'ctr' => $this->impressions_count > 0
                ? round(($this->clicks_count / $this->impressions_count) * 100, 2)
                : 0,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->only(['id', 'name'])),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
