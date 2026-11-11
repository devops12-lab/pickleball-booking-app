<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchConfirmation extends Model
{
    protected $fillable = [
        'match_id',
        'player_id',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(User::class, 'player_id');
    }
}
