<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Enums\DatabaseBackupAction;
use App\Enums\RestoreStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RestoreDatabaseBackupRequest;
use App\Http\Requests\Admin\RestoreUploadedDatabaseBackupRequest;
use App\Http\Requests\Admin\StoreDatabaseBackupRequest;
use App\Http\Requests\Admin\UploadDatabaseBackupRequest;
use App\Http\Resources\DatabaseBackupResource;
use App\Http\Resources\DatabaseRestoreLogResource;
use App\Http\Traits\ApiResponse;
use App\Jobs\CreateDatabaseBackupJob;
use App\Models\DatabaseBackup;
use App\Models\DatabaseRestoreLog;
use App\Services\DatabaseBackup\BackupException;
use App\Services\DatabaseBackupAuditService;
use App\Services\DatabaseBackupScheduleService;
use App\Services\DatabaseBackupService;
use App\Services\DatabaseRestoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatabaseBackupController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected DatabaseBackupService $backups,
        protected DatabaseRestoreService $restores,
        protected DatabaseBackupScheduleService $schedules,
        protected DatabaseBackupAuditService $audit,
    ) {}

    /**
     * List the backup history with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', DatabaseBackup::class);

        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $backups = DatabaseBackup::query()
            ->with('creator')
            ->when(
                in_array($request->input('status'), BackupStatus::values(), true),
                fn ($query) => $query->where('status', $request->input('status'))
            )
            ->when(
                in_array($request->input('type'), BackupType::values(), true),
                fn ($query) => $query->where('backup_type', $request->input('type'))
            )
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';

                $query->where(function ($query) use ($search): void {
                    $query->where('filename', 'like', $search)
                        ->orWhere('original_filename', 'like', $search)
                        ->orWhere('database_name', 'like', $search);
                });
            })
            ->latest('id')
            ->paginate($perPage);

        return $this->successResponse(
            data: DatabaseBackupResource::collection($backups),
            message: 'Backup history retrieved.',
            meta: [
                'current_page' => $backups->currentPage(),
                'per_page' => $backups->perPage(),
                'total' => $backups->total(),
                'last_page' => $backups->lastPage(),
            ]
        );
    }

    /**
     * Queue a manual database backup.
     */
    public function store(StoreDatabaseBackupRequest $request): JsonResponse
    {
        Gate::authorize('create', DatabaseBackup::class);

        try {
            $backup = $this->backups->createRecord(
                type: BackupType::Manual,
                userId: $request->user()?->id,
                metadata: array_filter([
                    'notes' => $request->validated('notes'),
                    'source' => 'api',
                ]),
            );

            CreateDatabaseBackupJob::dispatch($backup->id);
        } catch (BackupException $exception) {
            return $this->errorResponse(
                message: $this->backups->sanitize($exception->getMessage()),
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $this->successResponse(
            data: new DatabaseBackupResource($backup->load('creator')),
            message: 'Database backup queued.',
            code: Response::HTTP_ACCEPTED,
            meta: ['operation_id' => $backup->uuid]
        );
    }

    /**
     * Show a single backup record.
     */
    public function show(DatabaseBackup $backup): JsonResponse
    {
        Gate::authorize('view', $backup);

        return $this->successResponse(
            data: new DatabaseBackupResource($backup->load('creator')),
            message: 'Backup retrieved.'
        );
    }

    /**
     * Stream a completed backup as a .sql download.
     */
    public function download(DatabaseBackup $backup): JsonResponse|StreamedResponse
    {
        Gate::authorize('view', $backup);

        if (! $backup->status->isDownloadable()) {
            return $this->errorResponse(
                message: 'Only completed backups can be downloaded.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (! $this->backups->storage()->exists($backup->storage_path)) {
            return $this->errorResponse(
                message: 'The backup file is missing from storage.',
                code: Response::HTTP_NOT_FOUND
            );
        }

        $stream = $this->backups->storage()->readStream($backup->storage_path);

        $this->audit->record(
            action: DatabaseBackupAction::BackupDownloaded,
            backup: $backup,
            metadata: ['filename' => $backup->filename],
        );

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, $backup->filename, ['Content-Type' => 'application/sql']);
    }

    /**
     * Upload an external .sql dump.
     */
    public function upload(UploadDatabaseBackupRequest $request): JsonResponse
    {
        Gate::authorize('create', DatabaseBackup::class);

        try {
            $backup = $this->backups->upload($request->file('file'), $request->user());
        } catch (BackupException $exception) {
            return $this->errorResponse(
                message: $this->backups->sanitize($exception->getMessage()),
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $this->successResponse(
            data: new DatabaseBackupResource($backup->load('creator')),
            message: 'Backup uploaded. Uploaded dumps are never restored automatically.',
            code: Response::HTTP_CREATED
        );
    }

    /**
     * Queue the restore of an existing backup.
     */
    public function restore(RestoreDatabaseBackupRequest $request, DatabaseBackup $backup): JsonResponse
    {
        Gate::authorize('restore', $backup);

        try {
            $log = $this->restores->initiate(
                backup: $backup,
                user: $request->user(),
                confirmation: (string) $request->validated('confirmation'),
                acknowledge: $request->boolean('acknowledge'),
            );
        } catch (BackupException $exception) {
            return $this->errorResponse(
                message: $this->backups->sanitize($exception->getMessage()),
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $this->successResponse(
            data: new DatabaseRestoreLogResource($log->load(['executor', 'backup'])),
            message: 'Database restore queued.',
            code: Response::HTTP_ACCEPTED,
            meta: ['operation_id' => $log->uuid]
        );
    }

    /**
     * Upload and restore an external .sql dump in one explicit step.
     */
    public function restoreUploaded(RestoreUploadedDatabaseBackupRequest $request): JsonResponse
    {
        Gate::authorize('create', DatabaseBackup::class);
        Gate::authorize('restore', DatabaseBackup::class);

        try {
            $backup = $this->backups->upload($request->file('file'), $request->user());

            $log = $this->restores->initiate(
                backup: $backup,
                user: $request->user(),
                confirmation: (string) $request->validated('confirmation'),
                acknowledge: $request->boolean('acknowledge'),
            );
        } catch (BackupException $exception) {
            return $this->errorResponse(
                message: $this->backups->sanitize($exception->getMessage()),
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $this->successResponse(
            data: new DatabaseRestoreLogResource($log->load(['executor', 'backup'])),
            message: 'Uploaded backup accepted and restore queued.',
            code: Response::HTTP_ACCEPTED,
            meta: ['operation_id' => $log->uuid, 'backup_id' => $backup->id]
        );
    }

    /**
     * Delete a backup file. Pending and running backups cannot be removed.
     */
    public function destroy(Request $request, DatabaseBackup $backup): JsonResponse
    {
        Gate::authorize('delete', $backup);

        try {
            $this->backups->delete($backup, $request->user());
        } catch (BackupException $exception) {
            return $this->errorResponse(
                message: $this->backups->sanitize($exception->getMessage()),
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $this->successResponse(message: 'Backup deleted.');
    }

    /**
     * Backup and restore health for the admin dashboard.
     */
    public function monitoring(): JsonResponse
    {
        Gate::authorize('viewAny', DatabaseBackup::class);

        $statuses = DatabaseBackup::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $types = DatabaseBackup::query()
            ->selectRaw('backup_type, count(*) as aggregate')
            ->groupBy('backup_type')
            ->pluck('aggregate', 'backup_type');

        $latestCompleted = DatabaseBackup::query()
            ->completed()
            ->latest('completed_at')
            ->first(['id', 'uuid', 'filename', 'backup_type', 'file_size', 'completed_at']);

        $latestFailed = DatabaseBackup::query()
            ->where('status', BackupStatus::Failed->value)
            ->latest('id')
            ->first(['id', 'uuid', 'filename', 'error_message', 'completed_at']);

        $activeRestore = DatabaseRestoreLog::query()
            ->whereIn('restore_status', [RestoreStatus::Pending->value, RestoreStatus::Processing->value])
            ->latest('id')
            ->first();

        $latestRestore = DatabaseRestoreLog::query()->latest('id')->first();

        return $this->successResponse(
            data: [
                'is_enabled' => (bool) config('database-backup.enabled', true),
                'restore_enabled' => (bool) config('database-backup.restore.enabled', true),
                'storage_bytes' => (int) DatabaseBackup::query()->completed()->sum('file_size'),
                'backups_by_status' => $statuses,
                'backups_by_type' => $types,
                'latest_backup' => $latestCompleted !== null ? [
                    'id' => $latestCompleted->id,
                    'uuid' => $latestCompleted->uuid,
                    'filename' => $latestCompleted->filename,
                    'backup_type' => $latestCompleted->backup_type->value,
                    'file_size' => $latestCompleted->file_size,
                    'completed_at' => $latestCompleted->completed_at?->toIso8601String(),
                ] : null,
                'latest_failed_backup' => $latestFailed !== null ? [
                    'id' => $latestFailed->id,
                    'uuid' => $latestFailed->uuid,
                    'filename' => $latestFailed->filename,
                    'error_message' => $latestFailed->error_message,
                    'completed_at' => $latestFailed->completed_at?->toIso8601String(),
                ] : null,
                'next_scheduled_backup' => $this->schedules->nextScheduledBackup()?->toIso8601String(),
                'active_restore' => $activeRestore !== null ? new DatabaseRestoreLogResource($activeRestore) : null,
                'latest_restore' => $latestRestore !== null ? new DatabaseRestoreLogResource($latestRestore) : null,
            ],
            message: 'Backup monitoring retrieved.'
        );
    }
}
