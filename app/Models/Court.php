<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Court extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'facility_id',
        'tenant_id',
        'name',
        'court_code',
        'court_type',
        'surface_type',
        'hourly_rate',
        'is_active',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'hourly_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(CourtOperatingHour::class);
    }

    public function blockedSchedules(): HasMany
    {
        return $this->hasMany(CourtBlockedSchedule::class);
    }

    public function priceRules(): HasMany
    {
        return $this->hasMany(CourtPriceRule::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Gallery photos of the court (1 to 5, ordered).
     */
    public function images(): HasMany
    {
        return $this->hasMany(CourtImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function openPlaySessions(): HasMany
    {
        return $this->hasMany(OpenPlaySession::class);
    }
}
