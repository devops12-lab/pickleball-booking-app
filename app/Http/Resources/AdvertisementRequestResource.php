<?php

namespace App\Http\Resources;

use App\Models\AdvertisementRequest;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdvertisementRequest
 */
class AdvertisementRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $upload = app(FileUploadService::class);

        return [
            'id' => $this->id,
            'requester' => $this->whenLoaded('requester', fn () => $this->requester?->only(['id', 'name', 'email'])),
            'requester_type' => $this->requester_type,
            'tenant' => $this->whenLoaded('tenant', fn () => $this->tenant?->only(['id', 'name'])),
            'title' => $this->title,
            'description' => $this->description,
            'objective' => $this->objective,
            'requested_placement' => $this->requested_placement,
            'requested_start_date' => $this->requested_start_date?->toDateString(),
            'requested_end_date' => $this->requested_end_date?->toDateString(),
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'additional_requirements' => $this->additional_requirements,
            'attachment_url' => $this->when($this->attachment_path !== null, fn () => $upload->url($this->attachment_path)),
            'status' => $this->status,
            'admin_response' => $this->admin_response,
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->only(['id', 'name'])),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
