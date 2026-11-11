<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentMatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'tournament_category_id',
        'court_id',
        'match_id',
        'round_number',
        'match_number',
        'stage',
        'pool',
        'bye',
        'next_match_id',
        'next_side',
        'participant1_id',
        'participant2_id',
        'winner_id',
        'score',
        'status',
        'scheduled_at',
    ];

    protected function casts(): array
    {
        return [
            'round_number' => 'integer',
            'match_number' => 'integer',
            'match_id' => 'integer',
            'bye' => 'boolean',
            'next_side' => 'integer',
            'scheduled_at' => 'datetime',
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TournamentCategory::class, 'tournament_category_id');
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function participant1(): BelongsTo
    {
        return $this->belongsTo(TournamentParticipant::class, 'participant1_id');
    }

    public function participant2(): BelongsTo
    {
        return $this->belongsTo(TournamentParticipant::class, 'participant2_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(TournamentParticipant::class, 'winner_id');
    }
}
