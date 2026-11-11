<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerProfile extends Model
{
    use HasFactory;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'display_name',
        'skill_level',
        'bio',
        'play_formats',
        'preferred_times',
        'visibility',
        'avatar_style',
        'location_verified',
        'barangay_id',
    ];

    protected function casts(): array
    {
        return [
            'play_formats' => 'array',
            'preferred_times' => 'array',
            'location_verified' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(GeoLocation::class, 'barangay_id');
    }
}
