<?php

namespace App\Http\Controllers\Api\V1\Staff;

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
     * Daily operations staff dashboard.
     */
    public function index(): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();

        $stats = [
            'today_bookings' => Booking::where('tenant_id', $tenantId)
                ->whereDate('starts_at', today())
                ->with(['court', 'user:id,name'])
                ->get(),
            'pending_verifications' => Payment::where('tenant_id', $tenantId)
                ->where('status', 'pending')
                ->count(),
            'active_courts' => Court::where('tenant_id', $tenantId)->where('is_active', true)->count(),
        ];

        return $this->successResponse(
            data: $stats,
            message: 'Staff shift dashboard retrieved.'
        );
    }
}
