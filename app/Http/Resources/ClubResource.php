<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClubResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'creator_id' => $this->creator_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'logo' => $this->logo,
            'privacy' => $this->privacy,
            'max_members' => $this->max_members,
            'members_count' => $this->members_count ?? $this->whenCounted('members'),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        // Include player role within club if attached via pivot/membership
        if (isset($this->role)) {
            $data['role'] = $this->role;
        }

        return $data;
    }
}
