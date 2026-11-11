<?php

namespace App\Http\Requests\Courts;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCourtRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'court_type' => ['nullable', 'string', 'in:indoor,outdoor,covered'],
            'surface_type' => ['nullable', 'string', 'max:100'],
            'hourly_rate' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
