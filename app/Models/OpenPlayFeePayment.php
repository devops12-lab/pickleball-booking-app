<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpenPlayFeePayment extends Model
{
    protected $fillable = [
        'open_play_session_id',
        'open_play_participant_id',
        'kind',
        'payer_id',
        'amount',
        'method',
        'reference_number',
        'received_by',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(OpenPlaySession::class, 'open_play_session_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(OpenPlayParticipant::class, 'open_play_participant_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(OpenPlayFeeRefund::class);
    }
}
