<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourtResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'facility_id' => $this->facility_id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'court_type' => $this->court_type,
            'surface_type' => $this->surface_type,
            // Money is a decimal string in the SPA contract ("300.00"), never a float.
            'hourly_rate' => number_format((float) $this->hourly_rate, 2, '.', ''),
            'is_active' => $this->is_active,
        ];
    }
}
