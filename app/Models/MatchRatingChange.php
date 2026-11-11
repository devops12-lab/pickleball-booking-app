<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchRatingChange extends Model
{
    protected $fillable = [
        'match_id',
        'player_id',
        'type',
        'kind',
        'old_rating',
        'change',
        'new_rating',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'old_rating' => 'integer',
            'change' => 'integer',
            'new_rating' => 'integer',
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
