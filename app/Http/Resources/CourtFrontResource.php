<?php

namespace App\Http\Resources;

use App\Models\CourtFront;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CourtFront
 */
class CourtFrontResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $upload = app(FileUploadService::class);

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'tenant' => $this->whenLoaded('tenant', fn () => $this->tenant?->only(['id', 'name', 'slug', 'logo'])),
            'tagline' => $this->tagline,
            'description' => $this->description,
            'logo_url' => $this->when($this->logo !== null, fn () => $upload->url($this->logo)),
            'cover_image_url' => $this->when($this->cover_image !== null, fn () => $upload->url($this->cover_image)),
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'contact_address' => $this->contact_address,
            'website' => $this->website,
            'facebook' => $this->facebook,
            'instagram' => $this->instagram,
            'is_open' => $this->is_open,
        ];
    }
}
