<?php

namespace App\Http\Resources;

use App\Models\DatabaseBackupAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DatabaseBackupAuditLog
 */
class DatabaseBackupAuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'action' => $this->action,
            'user_id' => $this->user_id,
            'database_backup_id' => $this->database_backup_id,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'metadata' => $this->metadata,
            'user' => $this->whenLoaded('user', fn () => $this->user?->only(['id', 'name', 'email'])),
            'backup' => $this->whenLoaded('backup', fn () => $this->backup?->only(['id', 'uuid', 'filename'])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
