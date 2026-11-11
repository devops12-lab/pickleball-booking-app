<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tournament extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'venue_id',
        'organizer_id',
        'host_type',
        'host_id',
        'name',
        'slug',
        'description',
        'format',
        'match_type',
        'court_ids',
        'game',
        'starts_at',
        'ends_at',
        'registration_deadline',
        'entry_fee',
        'max_teams',
        'capacity',
        'pools',
        'champion_participant_id',
        'cancel_reason',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'registration_deadline' => 'datetime',
            'entry_fee' => 'decimal:2',
            'max_teams' => 'integer',
            'court_ids' => 'array',
            'game' => 'array',
            'capacity' => 'integer',
            'pools' => 'integer',
            'champion_participant_id' => 'integer',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'venue_id');
    }

    public function champion(): BelongsTo
    {
        return $this->belongsTo(TournamentParticipant::class, 'champion_participant_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(TournamentCategory::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(TournamentParticipant::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class);
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

    public function brackets(): HasMany
    {
        return $this->hasMany(TournamentBracket::class);
    }
}
