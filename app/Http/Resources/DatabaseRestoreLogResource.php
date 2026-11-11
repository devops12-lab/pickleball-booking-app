<?php

namespace App\Http\Resources;

use App\Models\DatabaseRestoreLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DatabaseRestoreLog
 */
class DatabaseRestoreLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'database_backup_id' => $this->database_backup_id,
            'original_filename' => $this->original_filename,
            'database_driver' => $this->database_driver,
            'database_name' => $this->database_name,
            'restore_status' => $this->restore_status->value,
            'restore_status_label' => $this->restore_status->label(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'error_message' => $this->error_message,
            'metadata' => $this->metadata,
            'executed_by' => $this->executed_by,
            'executor' => $this->whenLoaded('executor', fn () => $this->executor?->only(['id', 'name', 'email'])),
            'backup' => $this->whenLoaded('backup', fn () => new DatabaseBackupResource($this->backup)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
