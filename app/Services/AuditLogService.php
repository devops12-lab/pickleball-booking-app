<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogService
{
    /**
     * Record an audit log entry.
     */
    public function log(
        string $action,
        ?Model $entity = null,
        ?int $tenantId = null,
        ?User $user = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): AuditLog {
        $currentUser = $user ?? auth()->user();

        return AuditLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $currentUser?->id,
            'action' => $action,
            'entity_type' => $entity ? get_class($entity) : 'system',
            'entity_id' => $entity?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
