<?php

namespace App\Http\Controllers\Api\V1\Owners;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Booking;
use App\Models\Court;
use App\Models\Payment;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext
    ) {}

    /**
     * Facility owner business dashboard.
     */
    public function index(): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();

        $stats = [
            'total_courts' => Court::where('tenant_id', $tenantId)->count(),
            'active_courts' => Court::where('tenant_id', $tenantId)->where('is_active', true)->count(),
            'total_bookings' => Booking::where('tenant_id', $tenantId)->count(),
            'pending_bookings' => Booking::where('tenant_id', $tenantId)->where('status', 'pending')->count(),
            'today_bookings' => Booking::where('tenant_id', $tenantId)
                ->whereDate('starts_at', today())
                ->count(),
            'total_revenue' => (float) Payment::where('tenant_id', $tenantId)
                ->where('status', 'verified')
                ->sum('amount'),
        ];

        return $this->successResponse(
            data: $stats,
            message: 'Owner dashboard metrics retrieved.'
        );
    }
}
