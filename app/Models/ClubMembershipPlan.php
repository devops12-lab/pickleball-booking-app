<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClubMembershipPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'club_id',
        'name',
        'description',
        'price',
        'currency',
        'duration_days',
        'session_discount_percent',
        'perks',
        'recommended',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_days' => 'integer',
            'session_discount_percent' => 'integer',
            'perks' => 'array',
            'recommended' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ClubMember::class, 'plan_id');
    }
}
