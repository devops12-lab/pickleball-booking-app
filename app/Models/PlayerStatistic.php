<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerStatistic extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'matches_played',
        'matches_won',
        'matches_lost',
        'tournaments_played',
        'tournaments_won',
        'current_rating',
        'singles_rating',
        'doubles_rating',
        'singles_matches',
        'singles_wins',
        'singles_losses',
        'doubles_matches',
        'doubles_wins',
        'doubles_losses',
    ];

    protected function casts(): array
    {
        return [
            'matches_played' => 'integer',
            'matches_won' => 'integer',
            'matches_lost' => 'integer',
            'tournaments_played' => 'integer',
            'tournaments_won' => 'integer',
            'current_rating' => 'decimal:2',
            'singles_rating' => 'decimal:2',
            'doubles_rating' => 'decimal:2',
            'singles_matches' => 'integer',
            'singles_wins' => 'integer',
            'singles_losses' => 'integer',
            'doubles_matches' => 'integer',
            'doubles_wins' => 'integer',
            'doubles_losses' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
