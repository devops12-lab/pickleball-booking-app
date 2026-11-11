<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatingAdjustment extends Model
{
    protected $fillable = [
        'player_id',
        'adjusted_by',
        'type',
        'old_rating',
        'new_rating',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'old_rating' => 'integer',
            'new_rating' => 'integer',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(User::class, 'player_id');
    }

    public function adjuster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }
}
