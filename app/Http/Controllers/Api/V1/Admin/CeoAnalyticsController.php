<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\AnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CeoAnalyticsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AnalyticsService $analytics) {}

    public function overview(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);

        return $this->successResponse(
            data: $this->analytics->overview($start, $end),
            message: 'Analytics overview retrieved.',
        );
    }

    public function users(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);

        return $this->successResponse(
            data: $this->analytics->userAnalytics($start, $end, $this->period($request)),
            message: 'User analytics retrieved.',
        );
    }

    public function facilities(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);

        return $this->successResponse(
            data: $this->analytics->facilityAnalytics($start, $end, $this->period($request)),
            message: 'Facility analytics retrieved.',
        );
    }

    public function bookings(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);

        return $this->successResponse(
            data: $this->analytics->bookingAnalytics($start, $end, $this->period($request)),
            message: 'Booking analytics retrieved.',
        );
    }

    public function revenue(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);

        return $this->successResponse(
            data: $this->analytics->revenueAnalytics($start, $end, $this->period($request)),
            message: 'Revenue analytics retrieved.',
        );
    }

    public function subscriptions(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);

        return $this->successResponse(
            data: $this->analytics->subscriptionAnalytics($start, $end),
            message: 'Subscription analytics retrieved.',
        );
    }

    public function clubs(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);

        return $this->successResponse(
            data: $this->analytics->clubAnalytics($start, $end, $this->period($request)),
            message: 'Club analytics retrieved.',
        );
    }

    public function tournaments(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);

        return $this->successResponse(
            data: $this->analytics->tournamentAnalytics($start, $end, $this->period($request)),
            message: 'Tournament analytics retrieved.',
        );
    }

    public function advertisements(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDates($request);

        return $this->successResponse(
            data: $this->analytics->advertisementAnalytics($start, $end),
            message: 'Advertisement analytics retrieved.',
        );
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function parseDates(Request $request): array
    {
        $start = $request->filled('start_date') ? Carbon::parse($request->string('start_date')) : null;
        $end = $request->filled('end_date') ? Carbon::parse($request->string('end_date')) : null;

        return [$start, $end];
    }

    private function period(Request $request): string
    {
        $period = $request->string('period', 'monthly')->toString();

        return in_array($period, ['daily', 'weekly', 'monthly', 'quarterly', 'yearly']) ? $period : 'monthly';
    }
}
