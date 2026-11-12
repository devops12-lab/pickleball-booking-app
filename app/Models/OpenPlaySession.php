<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpenPlaySession extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'organizer_type',
        'organizer_id',
        'host_id',
        'court_id',
        'title',
        'description',
        'session_type',
        'skill_level',
        'starts_at',
        'ends_at',
        'registration_deadline',
        'max_participants',
        'fee',
        'member_fee',
        'members_only',
        'status',
        'status_reason',
        'court_fee',
        'queue_version',
        'skill_level_min',
        'skill_level_max',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'registration_deadline' => 'datetime',
            'fee' => 'decimal:2',
            'member_fee' => 'decimal:2',
            'court_fee' => 'decimal:2',
            'members_only' => 'boolean',
            'skill_level_min' => 'decimal:1',
            'skill_level_max' => 'decimal:1',
            'max_participants' => 'integer',
            'queue_version' => 'integer',
        ];
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function courts(): BelongsToMany
    {
        return $this->belongsToMany(Court::class, 'open_play_session_courts')
            ->withTimestamps();
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function organizerClub(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'organizer_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(OpenPlayParticipant::class);
    }

    public function feePayments(): HasMany
    {
        return $this->hasMany(OpenPlayFeePayment::class);
    }

    public function queueGames(): HasMany
    {
        return $this->hasMany(OpenPlayQueueGame::class);
    }
}
