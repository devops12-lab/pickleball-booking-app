<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'club_id',
        'invited_user_id',
        'invited_email',
        'invited_by',
        'token',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function invitedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_user_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
