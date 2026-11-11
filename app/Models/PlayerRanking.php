<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerRanking extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category',
        'rank',
        'points',
        'recorded_date',
    ];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'points' => 'integer',
            'recorded_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
