<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A played game. Tournament draw slots point at one of these through
 * tournament_matches.match_id, so scoring, confirmation and rating work the
 * same for casual, competitive and tournament play.
 */
class GameMatch extends Model
{
    use HasFactory;

    protected $table = 'matches';

    protected $fillable = [
        'match_type',
        'category',
        'format',
        'status',
        'venue_id',
        'court_id',
        'session_id',
        'tournament_id',
        'first_serving',
        'scorer_id',
        'created_by',
        'score',
        'winner_side',
        'started_at',
        'ended_at',
        'void_reason',
        'submitted_by',
        'submitted_at',
        'verified_by',
        'verified_at',
        'dispute_by',
        'dispute_reason',
        'dispute_at',
        'resolution_decision',
        'resolution_note',
        'resolution_by',
        'resolution_at',
    ];

    protected function casts(): array
    {
        return [
            'format' => 'array',
            'first_serving' => 'integer',
            'winner_side' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'dispute_at' => 'datetime',
            'resolution_at' => 'datetime',
        ];
    }

    public function players(): HasMany
    {
        return $this->hasMany(MatchPlayer::class, 'match_id')->orderBy('side')->orderBy('position');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MatchEvent::class, 'match_id')->orderBy('seq');
    }

    public function confirmations(): HasMany
    {
        return $this->hasMany(MatchConfirmation::class, 'match_id')->orderBy('confirmed_at');
    }

    public function ratingChanges(): HasMany
    {
        return $this->hasMany(MatchRatingChange::class, 'match_id')->orderBy('id');
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'venue_id');
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(OpenPlaySession::class, 'session_id');
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function tournamentSlot(): HasOne
    {
        return $this->hasOne(TournamentMatch::class, 'match_id');
    }

    public function scorer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scorer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function disputer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispute_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolution_by');
    }
}
