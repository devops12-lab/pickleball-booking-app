<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'price' => number_format((float) $this->price, 2, '.', ''),
            'billing_interval' => $this->billing_interval,
            'max_courts' => $this->max_courts,
            'max_staff' => $this->max_staff,
            'features' => $this->features,
            'is_active' => $this->is_active,
            'subscriptions_count' => $this->whenCounted('subscriptions'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
