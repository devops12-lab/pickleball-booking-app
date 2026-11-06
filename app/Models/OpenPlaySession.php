<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpenPlaySession extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'court_id',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'max_participants',
        'fee',
        'skill_level_min',
        'skill_level_max',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'fee' => 'decimal:2',
            'skill_level_min' => 'decimal:1',
            'skill_level_max' => 'decimal:1',
            'max_participants' => 'integer',
        ];
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(OpenPlayParticipant::class);
    }
}
