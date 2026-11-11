<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class GoogleAuthTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare data for validation, supporting standard Google Identity Services 'credential'.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('id_token') && $this->has('credential')) {
            $this->merge(['id_token' => $this->input('credential')]);
        }
    }

    public function rules(): array
    {
        return [
            'id_token' => ['required_without:credential', 'string'],
            'credential' => ['required_without:id_token', 'string'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'tenant_id' => ['nullable', 'integer'],
        ];
    }
}
