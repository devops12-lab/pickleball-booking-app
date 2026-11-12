<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A gallery photo of a court. Every court carries between 1 and 5 images,
 * enforced by the court management endpoints.
 *
 * @property int $id
 * @property int $court_id
 * @property string $image_path
 * @property int $sort_order
 *
 * @mixin \Eloquent
 */
class CourtImage extends Model
{
    /**
     * Maximum number of images a court may have.
     */
    public const MAX_PER_COURT = 5;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'court_id',
        'image_path',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * The court the image belongs to.
     */
    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }
}
