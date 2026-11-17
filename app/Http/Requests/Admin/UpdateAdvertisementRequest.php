<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdvertisementPlacement;
use App\Enums\AdvertisementTargetAudience;
use App\Enums\AdvertisementType;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAdvertisementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSystemAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'cta_label' => ['nullable', 'string', 'max:100'],
            'destination_url' => ['nullable', 'url', 'max:500'],
            'type' => ['sometimes', 'string', 'in:'.implode(',', AdvertisementType::values())],
            'placement' => ['sometimes', 'string', 'in:'.implode(',', AdvertisementPlacement::values())],
            'target_audience' => ['sometimes', 'string', 'in:'.implode(',', AdvertisementTargetAudience::values())],
            'media' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,mp4,webm', 'max:20480'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after:start_date'],
            'priority' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
