<?php

namespace App\Models;

use App\Enums\BackupFrequency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseBackupSchedule extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frequency' => BackupFrequency::class,
            'is_enabled' => 'boolean',
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'retention_count' => 'integer',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    /**
     * Administrator that last configured the schedule.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Determine whether the schedule is due at the given moment.
     */
    public function isDueAt(\DateTimeInterface $now): bool
    {
        if (! $this->is_enabled || ! $this->next_run_at) {
            return false;
        }

        return $this->next_run_at->lessThanOrEqualTo($now);
    }
}
