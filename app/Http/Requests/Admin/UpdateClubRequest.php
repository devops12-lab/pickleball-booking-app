<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClubRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSystemAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'privacy' => ['sometimes', 'string', 'in:public,private'],
            'max_members' => ['nullable', 'integer', 'min:2', 'max:10000'],
            'home_venue_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:active,pending_review,suspended,rejected'],
            'join_policy' => ['nullable', 'string', 'in:open,approval'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'rules' => ['nullable', 'string', 'max:10000'],
            'waiver' => ['nullable', 'string', 'max:10000'],
            'payment_options' => ['nullable', 'array'],
        ];
    }
}
