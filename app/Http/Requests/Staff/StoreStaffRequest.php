<?php

namespace App\Http\Requests\Staff;

use App\Enums\StaffPosition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreStaffRequest extends FormRequest
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
            // Staff accounts are created directly by the owner: the email must
            // not belong to an existing account to avoid linking personal
            // player accounts into a facility without consent.
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
            'phone' => ['nullable', 'string', 'max:50'],
            'position' => ['required', 'string', 'in:'.implode(',', StaffPosition::values())],
        ];
    }
}
