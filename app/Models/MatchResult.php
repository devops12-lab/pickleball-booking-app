<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MatchResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'matchable_type',
        'matchable_id',
        'participant1_id',
        'participant2_id',
        'winner_id',
        'scores',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'scores' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function matchable(): MorphTo
    {
        return $this->morphTo();
    }

    public function participant1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant1_id');
    }

    public function participant2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant2_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
