<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'google_id',
        'avatar',
        'account_status',
        'email_verified_at',
        'mfa_enabled',
        'mfa_secret',
    ];

    /**
     * Default model attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'phone' => null,
        'avatar' => null,
        'google_id' => null,
        'email_verified_at' => null,
        'account_status' => 'active',
        'mfa_enabled' => false,
        'mfa_secret' => null,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'mfa_enabled' => 'boolean',
        ];
    }

    /**
     * Tenant user pivots.
     */
    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    /**
     * Tenants the user belongs to.
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->withPivot('role_id', 'status')
            ->withTimestamps();
    }

    /**
     * External OAuth authentication providers linked to this account.
     */
    public function authProviders(): HasMany
    {
        return $this->hasMany(UserAuthProvider::class);
    }

    /**
     * Get list of all available roles for this user across all contexts.
     *
     * @return list<string>
     */
    public function getAvailableRoles(): array
    {
        $roles = ['player'];

        $tenantRoles = $this->tenantUsers()
            ->with('role')
            ->where('status', 'active')
            ->get()
            ->pluck('role.name')
            ->filter()
            ->all();

        $merged = array_unique(array_merge($roles, $tenantRoles));

        return array_values($merged);
    }

    /**
     * Direct custom user permission overrides.
     */
    public function userPermissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    /**
     * Bookings made by the user.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Payments made by the user.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Club memberships.
     */
    public function clubMemberships(): HasMany
    {
        return $this->hasMany(ClubMember::class);
    }

    /**
     * Player statistics record.
     */
    public function statistics(): HasOne
    {
        return $this->hasOne(PlayerStatistic::class);
    }

    /**
     * Global player profile; only players who set one up appear in player search.
     */
    public function playerProfile(): HasOne
    {
        return $this->hasOne(PlayerProfile::class);
    }

    /**
     * Account suspensions, including the active one and lifted history.
     */
    public function suspensions(): HasMany
    {
        return $this->hasMany(UserSuspension::class);
    }

    /**
     * Audit log entries initiated by the user.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Check if user account is active.
     */
    public function isActive(): bool
    {
        return $this->account_status === 'active';
    }

    /**
     * Check if user has System Admin role.
     */
    public function isSystemAdmin(): bool
    {
        return $this->hasRole('system_admin');
    }

    /**
     * Check if user is an owner of the given tenant.
     */
    public function isOwnerOf(int|Tenant $tenant): bool
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return $this->hasRole('owner', $tenantId);
    }

    /**
     * Check if user is a staff member of the given tenant.
     */
    public function isStaffOf(int|Tenant $tenant): bool
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return $this->hasRole('staff', $tenantId);
    }

    /**
     * Check if user holds a specific role either globally or within a specific tenant.
     */
    public function hasRole(string $roleName, ?int $tenantId = null): bool
    {
        $normalized = str_replace('-', '_', strtolower($roleName));

        // In CourtFlex, all active registered users have the baseline player capabilities
        if ($normalized === 'player') {
            return $this->isActive();
        }

        return $this->tenantUsers()
            ->whereHas('role', function ($query) use ($normalized): void {
                $query->where('name', $normalized);
            })
            ->when($tenantId !== null, function ($query) use ($tenantId): void {
                $query->where('tenant_id', $tenantId);
            })
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Check if user has a permission in platform scope or tenant scope.
     */
    public function hasPermission(string $permissionName, ?int $tenantId = null): bool
    {
        if ($this->isSystemAdmin()) {
            return true;
        }

        // Check direct revoked overrides first
        $isRevoked = $this->userPermissions()
            ->where('is_revoked', true)
            ->whereHas('permission', function ($query) use ($permissionName): void {
                $query->where('name', $permissionName);
            })
            ->when($tenantId !== null, function ($query) use ($tenantId): void {
                $query->where(function ($sub) use ($tenantId): void {
                    $sub->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
                });
            })
            ->exists();

        if ($isRevoked) {
            return false;
        }

        // Check direct granted overrides
        $hasDirectGrant = $this->userPermissions()
            ->where('is_revoked', false)
            ->whereHas('permission', function ($query) use ($permissionName): void {
                $query->where('name', $permissionName);
            })
            ->when($tenantId !== null, function ($query) use ($tenantId): void {
                $query->where(function ($sub) use ($tenantId): void {
                    $sub->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
                });
            })
            ->exists();

        if ($hasDirectGrant) {
            return true;
        }

        // Check role permissions through tenant_users
        return $this->tenantUsers()
            ->where('status', 'active')
            ->when($tenantId !== null, function ($query) use ($tenantId): void {
                $query->where('tenant_id', $tenantId);
            })
            ->whereHas('role.permissions', function ($query) use ($permissionName): void {
                $query->where('name', $permissionName);
            })
            ->exists();
    }
}
