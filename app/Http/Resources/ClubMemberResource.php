<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClubMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'club_id' => $this->club_id,
            'user_id' => $this->user_id,
            'role' => $this->role,
            'status' => $this->status,
            'joined_at' => $this->joined_at?->toISOString() ?? $this->created_at?->toISOString(),
            'user' => new UserResource($this->whenLoaded('user')),
            'club' => new ClubResource($this->whenLoaded('club')),
        ];
    }
}
