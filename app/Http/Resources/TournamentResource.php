<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TournamentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'organizer_id' => $this->organizer_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'format' => $this->format,
            'starts_at' => $this->starts_at?->toISOString() ?? (string) $this->starts_at,
            'ends_at' => $this->ends_at?->toISOString() ?? (string) $this->ends_at,
            'registration_deadline' => $this->registration_deadline?->toISOString() ?? (string) $this->registration_deadline,
            'entry_fee' => number_format((float) $this->entry_fee, 2, '.', ''),
            'max_teams' => $this->max_teams,
            'status' => $this->status,
            'tenant' => new TenantResource($this->whenLoaded('tenant')),
            'organizer' => new UserResource($this->whenLoaded('organizer')),
            'categories' => $this->whenLoaded('categories'),
            'participants_count' => $this->whenCounted('participants'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
