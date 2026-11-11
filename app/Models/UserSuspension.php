<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account suspension. The active suspension is the row without
 * `lifted_at`; reactivation fills the lift fields instead of deleting history.
 */
class UserSuspension extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'suspended_by',
        'reason',
        'suspended_at',
        'lifted_at',
        'lifted_by',
        'lift_reason',
    ];

    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }

    public function liftedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifted_by');
    }
}
