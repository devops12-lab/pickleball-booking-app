<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tenantUsers = $this->relationLoaded('tenantUsers')
            ? $this->tenantUsers
            : $this->tenantUsers()->with(['tenant', 'role'])->get();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->avatar,
            'account_status' => $this->account_status,
            'email_verified' => $this->email_verified_at !== null,
            'is_system_admin' => $this->isSystemAdmin(),
            'tenant_memberships' => $tenantUsers->map(function ($tu) {
                return [
                    'tenant_id' => $tu->tenant_id,
                    'tenant_name' => $tu->tenant?->name,
                    'tenant_slug' => $tu->tenant?->slug,
                    'role' => $tu->role?->name,
                    'role_label' => $tu->role?->label,
                    'status' => $tu->status,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
