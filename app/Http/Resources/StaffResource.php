<?php

namespace App\Http\Resources;

use App\Models\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantUser
 */
class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $position = $this->position;

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => $this->user?->only(['id', 'name', 'email', 'phone', 'avatar', 'account_status'])),
            'role' => $this->whenLoaded('role', fn () => $this->role?->only(['id', 'name', 'label'])),
            'position' => $position?->value,
            'position_label' => $position?->label(),
            'status' => $this->status,
            'joined_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
