<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpenPlayParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'open_play_session_id',
        'user_id',
        'payment_id',
        'status',
        'waitlist_position',
        'fee_due',
        'fee_paid',
        'fee_status',
        'queue_state',
        'queue_position',
        'queue_since',
        'games_played',
        'is_member',
        'check_in_status',
        'checked_in_at',
    ];

    protected function casts(): array
    {
        return [
            'fee_due' => 'decimal:2',
            'fee_paid' => 'decimal:2',
            'waitlist_position' => 'integer',
            'games_played' => 'integer',
            'queue_position' => 'integer',
            'queue_since' => 'datetime',
            'is_member' => 'boolean',
            'checked_in_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(OpenPlaySession::class, 'open_play_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
