<?php

namespace App\Http\Resources;

use App\Models\TenantPaymentMethod;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantPaymentMethod
 */
class TenantPaymentMethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'account_name' => $this->account_name,
            'account_number' => $this->account_number,
            'instructions' => $this->instructions,
            'qr_image_url' => $this->when($this->qr_image !== null, fn () => app(FileUploadService::class)->url($this->qr_image)),
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
