<?php

namespace App\Http\Resources;

use App\Models\DatabaseBackup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DatabaseBackup
 */
class DatabaseBackupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'filename' => $this->filename,
            'original_filename' => $this->original_filename,
            'database_driver' => $this->database_driver,
            'database_name' => $this->database_name,
            'backup_type' => $this->backup_type->value,
            'backup_type_label' => $this->backup_type->label(),
            'storage_disk' => $this->storage_disk,
            'storage_path' => $this->storage_path,
            'file_size' => $this->file_size,
            'checksum_sha256' => $this->checksum_sha256,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'error_message' => $this->error_message,
            'can_download' => $this->status->isDownloadable(),
            'can_restore' => $this->status->isRestorable(),
            'metadata' => $this->metadata,
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->only(['id', 'name', 'email'])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
