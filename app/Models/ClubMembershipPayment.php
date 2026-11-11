<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubMembershipPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'club_id',
        'club_member_id',
        'payer_id',
        'amount',
        'method',
        'reference_number',
        'received_by',
        'received_at',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ClubMember::class, 'club_member_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
