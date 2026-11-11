<?php

namespace App\Http\Requests\Staff;

use App\Enums\StaffPosition;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffRequest extends FormRequest
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
            'position' => ['sometimes', 'required', 'string', 'in:'.implode(',', StaffPosition::values())],
            'status' => ['sometimes', 'required', 'string', 'in:active,inactive'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedPayload(): array
    {
        return array_intersect_key($this->validated(), array_flip(['position', 'status']));
    }
}
