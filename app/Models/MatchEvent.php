<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchEvent extends Model
{
    protected $fillable = [
        'match_id',
        'seq',
        'event_uuid',
        'type',
        'winner',
        'scorer_id',
    ];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'winner' => 'integer',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function scorer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scorer_id');
    }
}
