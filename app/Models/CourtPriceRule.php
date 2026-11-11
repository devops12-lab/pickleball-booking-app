<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rate that applies to part of a court's day (e.g. 17:00-22:00 costs more).
 * Outside every rule the court's base rate applies.
 */
class CourtPriceRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'court_id',
        'day_of_week',
        'starts_at',
        'ends_at',
        'price',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }
}
