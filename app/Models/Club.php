<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Club extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'creator_id',
        'name',
        'slug',
        'description',
        'logo',
        'privacy',
        'max_members',
        'home_venue_id',
        'tagline',
        'city',
        'province',
        'cover',
        'status',
        'rules',
        'waiver',
        'contact_email',
        'contact_phone',
        'join_policy',
        'cancellation_hours',
        'payment_options',
    ];

    protected function casts(): array
    {
        return [
            'max_members' => 'integer',
            'cancellation_hours' => 'integer',
            'payment_options' => 'array',
        ];
    }

    public function homeVenue(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'home_venue_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ClubMember::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(ClubInvitation::class);
    }

    public function membershipPlans(): HasMany
    {
        return $this->hasMany(ClubMembershipPlan::class)->orderBy('price')->orderBy('id');
    }

    public function membershipPayments(): HasMany
    {
        return $this->hasMany(ClubMembershipPayment::class);
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
