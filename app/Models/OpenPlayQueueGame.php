<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpenPlayQueueGame extends Model
{
    use HasFactory;

    protected $fillable = [
        'open_play_session_id',
        'court_id',
        'team_a',
        'team_b',
        'started_at',
        'ended_at',
        'winner',
    ];

    protected function casts(): array
    {
        return [
            'team_a' => 'array',
            'team_b' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(OpenPlaySession::class, 'open_play_session_id');
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }
}
