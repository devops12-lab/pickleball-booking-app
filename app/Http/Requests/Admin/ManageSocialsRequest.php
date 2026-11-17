<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ManageSocialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', 'in:facebook,instagram,tiktok,youtube,linkedin,twitter,website'],
            'url' => ['required', 'url', 'max:500'],
            'display_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
