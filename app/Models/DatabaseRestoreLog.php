<?php

namespace App\Models;

use App\Enums\RestoreStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DatabaseRestoreLog extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'database_backup_id',
        'original_filename',
        'database_driver',
        'database_name',
        'restore_status',
        'started_at',
        'completed_at',
        'duration_seconds',
        'executed_by',
        'error_message',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'restore_status' => RestoreStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_seconds' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            $log->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Backup that was restored, when it is still tracked.
     */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(DatabaseBackup::class, 'database_backup_id');
    }

    /**
     * Administrator that triggered the restore.
     */
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
