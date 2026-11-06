<?php

namespace App\Http\Requests\Uploads;

use Illuminate\Foundation\Http\FormRequest;

class UploadPaymentProofRequest extends FormRequest
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
            'proof' => [
                'required',
                'file',
                'mimes:jpeg,jpg,png,pdf',
                'max:5120', // 5MB max
            ],
            'transaction_reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
