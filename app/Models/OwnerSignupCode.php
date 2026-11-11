<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OwnerSignupCode extends Model
{
    protected $fillable = [
        'name',
        'email',
        'code_hash',
        'attempts',
        'last_sent_at',
        'expires_at',
        'verified_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
