<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailVerificationCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'code_hash',
        'attempts',
        'last_sent_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
