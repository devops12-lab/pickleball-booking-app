<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'tournament_category_id',
        'user_id',
        'partner_id',
        'team_name',
        'payment_id',
        'seed',
        'rating',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'seed' => 'integer',
            'rating' => 'integer',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
