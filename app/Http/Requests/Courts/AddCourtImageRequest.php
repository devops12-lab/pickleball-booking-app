<?php

namespace App\Http\Requests\Courts;

use App\Models\CourtImage;
use Illuminate\Foundation\Http\FormRequest;

class AddCourtImageRequest extends FormRequest
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
            // The controller enforces the combined court total stays at 5 or fewer.
            'images' => ['required', 'array', 'min:1', 'max:'.CourtImage::MAX_PER_COURT],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
