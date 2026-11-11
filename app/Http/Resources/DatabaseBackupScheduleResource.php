<?php

namespace App\Http\Resources;

use App\Models\DatabaseBackupSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DatabaseBackupSchedule
 */
class DatabaseBackupScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'frequency' => $this->frequency->value,
            'frequency_label' => $this->frequency->label(),
            'is_enabled' => $this->is_enabled,
            'execution_time' => $this->execution_time,
            'day_of_week' => $this->day_of_week,
            'day_of_month' => $this->day_of_month,
            'timezone' => $this->timezone,
            'retention_count' => $this->retention_count,
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'next_run_at' => $this->next_run_at?->toIso8601String(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
