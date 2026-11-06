<?php

namespace App\Http\Requests\Clubs;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClubRequest extends FormRequest
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
            'description' => ['nullable', 'string'],
            'privacy' => ['sometimes', 'string', 'in:public,private,invite_only'],
            'max_members' => ['nullable', 'integer', 'min:5', 'max:500'],
        ];
    }
}
