<?php

namespace App\Http\Requests\Auth;

use App\Rules\FullName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255', new FullName],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:25'],
            'avatar' => ['nullable', 'string', 'max:500'],
            'in_game_name' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('player_profiles', 'in_game_name')->ignore($userId, 'user_id')],
        ];
    }
}
