<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TournamentCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'name',
        'division',
        'skill_level',
    ];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(TournamentParticipant::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class);
    }
}
