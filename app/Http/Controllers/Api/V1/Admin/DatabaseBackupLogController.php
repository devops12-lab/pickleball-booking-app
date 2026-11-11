<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DatabaseBackupAuditLogResource;
use App\Http\Resources\DatabaseRestoreLogResource;
use App\Http\Traits\ApiResponse;
use App\Models\DatabaseBackup;
use App\Models\DatabaseBackupAuditLog;
use App\Models\DatabaseRestoreLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DatabaseBackupLogController extends Controller
{
    use ApiResponse;

    /**
     * List restore attempts so long running operations can be polled.
     */
    public function restores(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', DatabaseBackup::class);

        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $logs = DatabaseRestoreLog::query()
            ->with(['executor', 'backup'])
            ->when($request->filled('status'), fn ($query) => $query->where('restore_status', $request->input('status')))
            ->when($request->filled('backup_id'), fn ($query) => $query->where('database_backup_id', $request->integer('backup_id')))
            ->latest('id')
            ->paginate($perPage);

        return $this->successResponse(
            data: DatabaseRestoreLogResource::collection($logs),
            message: 'Restore operations retrieved.',
            meta: [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ]
        );
    }

    /**
     * Poll the status of a single restore operation.
     */
    public function showRestore(DatabaseRestoreLog $restoreLog): JsonResponse
    {
        Gate::authorize('viewAny', DatabaseBackup::class);

        return $this->successResponse(
            data: new DatabaseRestoreLogResource($restoreLog->load(['executor', 'backup'])),
            message: 'Restore operation retrieved.'
        );
    }

    /**
     * List the dedicated database backup audit trail.
     */
    public function auditLogs(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', DatabaseBackup::class);

        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $logs = DatabaseBackupAuditLog::query()
            ->with(['user', 'backup'])
            ->when($request->filled('action'), fn ($query) => $query->where('action', $request->input('action')))
            ->when($request->filled('backup_id'), fn ($query) => $query->where('database_backup_id', $request->integer('backup_id')))
            ->latest('id')
            ->paginate($perPage);

        return $this->successResponse(
            data: DatabaseBackupAuditLogResource::collection($logs),
            message: 'Backup audit log retrieved.',
            meta: [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ]
        );
    }
}
