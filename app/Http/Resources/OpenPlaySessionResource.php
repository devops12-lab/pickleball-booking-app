<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OpenPlaySessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'court_id' => $this->court_id,
            'title' => $this->title,
            'description' => $this->description,
            'skill_level_min' => $this->skill_level_min,
            'skill_level_max' => $this->skill_level_max,
            'starts_at' => $this->starts_at?->toISOString() ?? (string) $this->starts_at,
            'ends_at' => $this->ends_at?->toISOString() ?? (string) $this->ends_at,
            'fee' => number_format((float) $this->fee, 2, '.', ''),
            'max_participants' => $this->max_participants,
            'status' => $this->status,
            'court' => new CourtResource($this->whenLoaded('court')),
            'participants_count' => $this->whenCounted('participants'),
            'participants' => $this->whenLoaded('participants'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
