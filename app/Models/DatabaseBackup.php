<?php

namespace App\Models;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A snapshot of the application database taken manually, on schedule, or uploaded.
 *
 * Each backup tracks its storage location, checksum, status lifecycle, and the
 * administrator who created it. Restore logs and audit entries are attached as
 * child records.
 *
 * @property int $id
 * @property string $uuid
 * @property string $filename
 * @property string|null $original_filename
 * @property string $database_driver
 * @property string $database_name
 * @property BackupType $backup_type
 * @property string $storage_disk
 * @property string $storage_path
 * @property int $file_size
 * @property string|null $checksum_sha256
 * @property BackupStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $duration_seconds
 * @property int|null $created_by
 * @property string|null $error_message
 * @property array|null $metadata
 */
class DatabaseBackup extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'filename',
        'original_filename',
        'database_driver',
        'database_name',
        'backup_type',
        'storage_disk',
        'storage_path',
        'file_size',
        'checksum_sha256',
        'status',
        'started_at',
        'completed_at',
        'duration_seconds',
        'created_by',
        'error_message',
        'metadata',
        'created_at',
    ];

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
