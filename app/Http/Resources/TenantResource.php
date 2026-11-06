<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'business_email' => $this->business_email,
            'business_phone' => $this->business_phone,
            'address' => $this->address,
            'logo' => $this->logo,
            'status' => $this->status,
            'subscription_plan_id' => $this->subscription_plan_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
