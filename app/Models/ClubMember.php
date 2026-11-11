<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'club_id',
        'user_id',
        'role',
        'status',
        'plan_id',
        'joined_at',
        'starts_at',
        'expires_at',
        'requested_at',
        'application',
        'payment',
        'dues_due',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'requested_at' => 'datetime',
            'application' => 'array',
            'payment' => 'array',
            'dues_due' => 'decimal:2',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ClubMembershipPlan::class, 'plan_id');
    }

    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ClubMemberEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ClubMembershipPayment::class);
    }
}
