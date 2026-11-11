<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'booking_id' => $this->booking_id,
            'user_id' => $this->user_id,
            'amount' => number_format((float) $this->amount, 2, '.', ''),
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'transaction_reference' => $this->transaction_reference,
            'status' => $this->status,
            'proof_attachment' => $this->proof_attachment,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
