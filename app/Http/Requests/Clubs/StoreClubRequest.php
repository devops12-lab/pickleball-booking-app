<?php

namespace App\Http\Requests\Clubs;

use Illuminate\Foundation\Http\FormRequest;

class StoreClubRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'privacy' => ['required', 'string', 'in:public,private,invite_only'],
            'max_members' => ['nullable', 'integer', 'min:5', 'max:500'],
        ];
    }
}
