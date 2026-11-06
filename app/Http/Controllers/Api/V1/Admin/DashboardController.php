<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    use ApiResponse;

    /**
     * Platform administrator dashboard metrics.
     */
    public function index(): JsonResponse
    {
        $metrics = [
            'total_tenants' => Tenant::count(),
            'active_tenants' => Tenant::where('status', 'active')->count(),
            'total_users' => User::count(),
            'total_bookings' => Booking::count(),
            'total_revenue' => (float) Payment::where('status', 'verified')->sum('amount'),
            'recent_audit_logs' => AuditLog::latest()->take(5)->get(),
        ];

        return $this->successResponse(
            data: $metrics,
            message: 'Platform statistics retrieved.'
        );
    }
}
