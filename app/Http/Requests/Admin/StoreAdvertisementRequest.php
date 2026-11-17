<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdvertisementPlacement;
use App\Enums\AdvertisementTargetAudience;
use App\Enums\AdvertisementType;
use Illuminate\Foundation\Http\FormRequest;

class StoreAdvertisementRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'cta_label' => ['nullable', 'string', 'max:100'],
            'destination_url' => ['nullable', 'url', 'max:500'],
            'type' => ['required', 'string', 'in:'.implode(',', AdvertisementType::values())],
            'placement' => ['required', 'string', 'in:'.implode(',', AdvertisementPlacement::values())],
            'target_audience' => ['sometimes', 'string', 'in:'.implode(',', AdvertisementTargetAudience::values())],
            'media' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,mp4,webm', 'max:20480'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'priority' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
