<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'court_id',
        'user_id',
        'guest_name',
        'guest_phone',
        'guest_email',
        'created_by',
        'booking_reference',
        'starts_at',
        'ends_at',
        'total_amount',
        'status',
        'source',
        'payment_method',
        'hold_expires_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'hold_expires_at' => 'datetime',
        ];
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(BookingParticipant::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(BookingReceipt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
