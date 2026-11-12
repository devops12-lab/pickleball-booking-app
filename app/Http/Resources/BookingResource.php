<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'court_id' => $this->court_id,
            'court_name' => $this->whenLoaded('court', fn () => $this->court->name),
            'user_id' => $this->user_id,
            'booking_reference' => $this->booking_reference,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'total_amount' => number_format((float) $this->total_amount, 2, '.', ''),
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
