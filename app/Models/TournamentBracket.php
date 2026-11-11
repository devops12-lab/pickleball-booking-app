<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentBracket extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'tournament_category_id',
        'bracket_data',
    ];

    protected function casts(): array
    {
        return [
            'bracket_data' => 'array',
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
}
