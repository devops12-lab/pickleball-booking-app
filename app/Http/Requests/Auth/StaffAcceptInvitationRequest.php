<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StaffAcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
            'id_token' => ['nullable', 'string'], // If linking with Google
        ];
    }
}
