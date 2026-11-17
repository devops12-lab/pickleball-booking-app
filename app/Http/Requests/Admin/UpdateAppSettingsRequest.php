<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppSettingsRequest extends FormRequest
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
            'app_name' => ['sometimes', 'string', 'max:100'],
            'app_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'app_logo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'app_favicon' => ['sometimes', 'nullable', 'image', 'mimes:ico,png,svg', 'max:1024'],
            'official_website' => ['sometimes', 'nullable', 'url', 'max:500'],
            'business_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'ad_inquiry_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'ad_contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'ad_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'default_timezone' => ['sometimes', 'string', 'timezone'],
            'default_currency' => ['sometimes', 'string', 'size:3'],
            'maintenance_mode' => ['sometimes', 'boolean'],
            'public_booking_enabled' => ['sometimes', 'boolean'],
            'registration_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
