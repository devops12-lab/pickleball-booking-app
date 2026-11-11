<?php

namespace App\Http\Requests\Uploads;

use App\Models\Payment;
use App\Models\TenantPaymentMethod;
use Closure;
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
            // The chosen channel must be one the facility actually accepts.
            'payment_method' => [
                'nullable',
                'string',
                'max:100',
                function (string $attribute, mixed $value, Closure $fail): void {
                    /** @var Payment|null $payment */
                    $payment = $this->route('payment');

                    if ($payment === null) {
                        return;
                    }

                    $accepted = TenantPaymentMethod::query()
                        ->where('tenant_id', $payment->tenant_id)
                        ->where('is_active', true)
                        ->pluck('name');

                    if (! $accepted->contains($value)) {
                        $fail('The selected payment method is not accepted by this facility.');
                    }
                },
            ],
        ];
    }
}
