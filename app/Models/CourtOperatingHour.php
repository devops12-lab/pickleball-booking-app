<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourtOperatingHour extends Model
{
    use HasFactory;

    protected $fillable = [
        'court_id',
        'day_of_week',
        'open_time',
        'close_time',
        'is_closed',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_closed' => 'boolean',
        ];
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }
}
