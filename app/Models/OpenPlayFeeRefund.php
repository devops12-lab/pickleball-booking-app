<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpenPlayFeeRefund extends Model
{
    protected $fillable = [
        'open_play_fee_payment_id',
        'amount',
        'reason',
        'refunded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(OpenPlayFeePayment::class, 'open_play_fee_payment_id');
    }
}
