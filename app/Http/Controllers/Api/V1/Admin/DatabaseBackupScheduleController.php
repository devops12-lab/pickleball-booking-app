<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDatabaseBackupScheduleRequest;
use App\Http\Resources\DatabaseBackupResource;
use App\Http\Resources\DatabaseBackupScheduleResource;
use App\Http\Traits\ApiResponse;
use App\Jobs\CreateDatabaseBackupJob;
use App\Models\DatabaseBackup;
use App\Models\DatabaseBackupSchedule;
use App\Services\DatabaseBackup\BackupException;
use App\Services\DatabaseBackupScheduleService;
use App\Services\DatabaseBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class DatabaseBackupScheduleController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected DatabaseBackupScheduleService $schedules,
        protected DatabaseBackupService $backups,
    ) {}

    /**
     * List the persisted daily, weekly and monthly schedules.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('manageSchedule', DatabaseBackup::class);

        return $this->successResponse(
            data: DatabaseBackupScheduleResource::collection($this->schedules->ensureDefaults()),
            message: 'Backup schedules retrieved.'
        );
    }

    /**
     * Update a schedule without editing configuration files.
     */
    public function update(UpdateDatabaseBackupScheduleRequest $request, DatabaseBackupSchedule $schedule): JsonResponse
    {
        Gate::authorize('manageSchedule', DatabaseBackup::class);

        try {
            $schedule = $this->schedules->update($schedule, $request->validated(), $request->user());
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse(
                message: $exception->getMessage(),
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $this->successResponse(
            data: new DatabaseBackupScheduleResource($schedule),
            message: 'Backup schedule updated.'
        );
    }

    /**
     * Queue an immediate backup for a schedule without shifting its cadence.
     */
    public function run(DatabaseBackupSchedule $schedule): JsonResponse
    {
        Gate::authorize('create', DatabaseBackup::class);

        try {
            $backup = $this->backups->createRecord($schedule->frequency->backupType());

            CreateDatabaseBackupJob::dispatch($backup->id);
        } catch (BackupException $exception) {
            return $this->errorResponse(
                message: $this->backups->sanitize($exception->getMessage()),
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $this->successResponse(
            data: new DatabaseBackupResource($backup->load('creator')),
            message: "{$schedule->frequency->label()} backup queued.",
            code: Response::HTTP_ACCEPTED,
            meta: ['operation_id' => $backup->uuid]
        );
    }
}
