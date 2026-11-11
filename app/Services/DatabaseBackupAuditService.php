<?php

namespace App\Services;

use App\Enums\DatabaseBackupAction;
use App\Models\DatabaseBackup;
use App\Models\DatabaseBackupAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Writes the dedicated database backup audit trail.
 *
 * Metadata is sanitised so credentials and SQL contents can never be stored.
 */
class DatabaseBackupAuditService
{
    private const SENSITIVE_KEYS = [
        'password',
        'pass',
        'secret',
        'token',
        'sql',
        'sql_content',
        'contents',
        'dump',
        'checksum_source',
    ];

    /**
     * Record an administrative backup or restore action.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        DatabaseBackupAction $action,
        ?DatabaseBackup $backup = null,
        ?User $user = null,
        array $metadata = [],
        ?Request $request = null,
    ): DatabaseBackupAuditLog {
        $request ??= app()->runningInConsole() ? null : request();

        return DatabaseBackupAuditLog::create([
            'action' => $action->value,
            'user_id' => ($user ?? auth()->user())?->id,
            'database_backup_id' => $backup?->id,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $this->sanitize($metadata),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitize(array $metadata): array
    {
        $clean = [];

        foreach ($metadata as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            foreach (self::SENSITIVE_KEYS as $sensitive) {
                if (str_contains($normalizedKey, $sensitive)) {
                    continue 2;
                }
            }

            $clean[$key] = match (true) {
                is_string($value) => mb_substr($value, 0, 500),
                is_scalar($value), $value === null => $value,
                default => null,
            };
        }

        return $clean;
    }
}
