<?php

namespace App\Http\Controllers\Api\V1\Owners;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TenantContextService $tenantContext
    ) {}

    /**
     * Facility financial and utilization reports.
     */
    public function index(): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();

        $driver = DB::connection()->getDriverName();
        $monthExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        $monthlyRevenue = Payment::where('tenant_id', $tenantId)
            ->where('status', 'verified')
            ->selectRaw("{$monthExpr} as month, SUM(amount) as revenue")
            ->groupBy('month')
            ->orderByDesc('month')
            ->take(12)
            ->get();

        $courtUtilization = Booking::where('tenant_id', $tenantId)
            ->with('court:id,name')
            ->selectRaw('court_id, COUNT(*) as total_bookings, SUM(total_amount) as total_earnings')
            ->groupBy('court_id')
            ->get();

        return $this->successResponse(
            data: [
                'monthly_revenue' => $monthlyRevenue,
                'court_utilization' => $courtUtilization,
            ],
            message: 'Facility operational reports generated.'
        );
    }
}
