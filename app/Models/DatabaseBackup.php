<?php

namespace App\Models;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DatabaseBackup extends Model
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
            'backup_type' => BackupType::class,
            'status' => BackupStatus::class,
            'file_size' => 'integer',
            'duration_seconds' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $backup): void {
            $backup->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Administrator or scheduler that requested the backup.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Restore attempts performed with this backup.
     */
    public function restoreLogs(): HasMany
    {
        return $this->hasMany(DatabaseRestoreLog::class);
    }

    /**
     * Audit entries recorded for this backup.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(DatabaseBackupAuditLog::class);
    }

    /**
     * Limit the query to completed backups.
     */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', BackupStatus::Completed->value);
    }

    /**
     * Determine whether the backup is currently locked by an operation.
     */
    public function isBusy(): bool
    {
        return in_array($this->status, [BackupStatus::Pending, BackupStatus::Processing], true);
    }
}
