<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontSocialLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'court_front_id',
        'platform',
        'display_name',
        'url',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function courtFront(): BelongsTo
    {
        return $this->belongsTo(CourtFront::class);
    }
}
