<?php

namespace App\Http\Requests\Courts;

use App\Models\CourtImage;
use Illuminate\Foundation\Http\FormRequest;

class StoreCourtRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'name' => ['required', 'string', 'max:255'],
            'court_type' => ['nullable', 'string', 'in:indoor,outdoor,covered'],
            'surface_type' => ['nullable', 'string', 'max:100'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            // Every court must carry between 1 and 5 gallery photos.
            'images' => ['required', 'array', 'min:1', 'max:'.CourtImage::MAX_PER_COURT],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
