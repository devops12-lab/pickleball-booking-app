<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Venue-facing profile for a tenant, shaped for the SPA's `Club` contract.
 */
class VenueProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'barangay_id',
        'tagline',
        'description',
        'rules',
        'cover_url',
        'contact_phone',
        'contact_email',
        'address',
        'city',
        'province',
        'latitude',
        'longitude',
        'amenities',
        'timezone',
        'status',
        'status_reason',
        'submitted_at',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'amenities' => 'array',
            'submitted_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
