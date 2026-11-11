<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'business_email',
        'business_phone',
        'address',
        'logo',
        'status',
        'subscription_plan_id',
    ];

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(TenantSubscription::class);
    }

    /**
     * Venue-facing profile (tagline, rules, timezone, settings) for the SPA.
     */
    public function venueProfile(): HasOne
    {
        return $this->hasOne(VenueProfile::class);
    }

    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->withPivot('role_id', 'status')
            ->withTimestamps();
    }

    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class);
    }

    public function courts(): HasMany
    {
        return $this->hasMany(Court::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function tournaments(): HasMany
    {
        return $this->hasMany(Tournament::class);
    }

    public function openPlaySessions(): HasMany
    {
        return $this->hasMany(OpenPlaySession::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Resolve the route binding by either numeric ID or slug.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if ($field) {
            return parent::resolveRouteBinding($value, $field);
        }

        return is_numeric($value)
            ? $this->where($this->getRouteKeyName(), $value)->first()
            : $this->where('slug', $value)->first();
    }
}
