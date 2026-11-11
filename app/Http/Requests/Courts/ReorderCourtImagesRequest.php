<?php

namespace App\Http\Requests\Courts;

use App\Models\Court;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReorderCourtImagesRequest extends FormRequest
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
        /** @var Court|null $court */
        $court = $this->route('court');

        return [
            // The ordered list must contain exactly the court's existing images.
            'image_ids' => ['required', 'array', 'size:'.$court?->images()->count()],
            'image_ids.*' => [
                'required',
                'integer',
                Rule::exists('court_images', 'id')->where('court_id', $court?->id),
            ],
        ];
    }
}
