<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A six-digit code sent to a guest's email. The code itself is hashed; a row is
 * used once and expires after ten minutes.
 */
class GuestBookingCode extends Model
{
    protected $fillable = [
        'email',
        'name',
        'code_hash',
        'attempts',
        'used_at',
        'expires_at',
        'last_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'used_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }
}
