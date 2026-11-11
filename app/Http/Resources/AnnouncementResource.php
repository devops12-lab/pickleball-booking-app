<?php

namespace App\Http\Resources;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Announcement
 */
class AnnouncementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'title' => $this->title,
            'body' => $this->body,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'audience' => $this->audience->value,
            'audience_label' => $this->audience->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'publish_at' => $this->publish_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_pinned' => $this->is_pinned,
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->only(['id', 'name', 'email'])),
            'read_count' => $this->whenCounted('reads'),
            'is_read' => $this->whenLoaded('reads', fn () => $this->reads->isNotEmpty()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
