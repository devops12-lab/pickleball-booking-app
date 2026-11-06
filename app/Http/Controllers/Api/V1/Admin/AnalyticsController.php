<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Traits\ApiResponse;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    use ApiResponse;

    /**
     * Platform analytics overview.
     */
    public function analytics(): JsonResponse
    {
        $userGrowth = User::selectRaw('DATE(created_at) as date, count(*) as count')
            ->groupBy('date')
            ->orderByDesc('date')
            ->take(30)
            ->get();

        return $this->successResponse(
            data: [
                'user_growth' => $userGrowth,
            ],
            message: 'Analytics data retrieved.'
        );
    }

    /**
     * View system audit logs.
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $logs = AuditLog::with('user:id,name,email')
            ->when($request->action, fn ($q, $a) => $q->where('action', $a))
            ->when($request->tenant_id, fn ($q, $t) => $q->where('tenant_id', $t))
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return $this->successResponse(
            data: AuditLogResource::collection($logs),
            meta: [
                'current_page' => $logs->currentPage(),
                'total' => $logs->total(),
            ]
        );
    }

    /**
     * View platform settings.
     */
    public function settings(): JsonResponse
    {
        return $this->successResponse(
            data: PlatformSetting::all(),
            message: 'Platform settings retrieved.'
        );
    }
}
