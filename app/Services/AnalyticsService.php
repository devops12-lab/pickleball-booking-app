<?php

namespace App\Services;

use App\Models\Advertisement;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\SubscriptionInvoice;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\Tournament;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Combined overview of all analytics categories.
     *
     * @return array<string, mixed>
     */
    public function overview(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        [$start, $end] = $this->resolveDates($startDate, $endDate);

        return Cache::remember("analytics:overview:{$start}:{$end}", self::CACHE_TTL, fn (): array => [
            'users' => [
                'total' => User::count(),
                'new_in_period' => User::whereBetween('created_at', [$start, $end])->count(),
            ],
            'facilities' => [
                'total' => Facility::count(),
                'active' => Facility::where('is_active', true)->count(),
            ],
            'bookings' => [
                'total' => Booking::count(),
                'in_period' => Booking::whereBetween('created_at', [$start, $end])->count(),
            ],
            'revenue' => [
                'total_collected' => (float) Payment::where('status', 'verified')->sum('amount'),
                'in_period' => (float) Payment::where('status', 'verified')->whereBetween('created_at', [$start, $end])->sum('amount'),
            ],
            'subscriptions' => [
                'active' => TenantSubscription::where('status', 'active')->count(),
                'trialing' => TenantSubscription::where('status', 'trialing')->count(),
            ],
            'clubs' => [
                'total' => Club::count(),
            ],
            'tournaments' => [
                'total' => Tournament::count(),
            ],
            'advertisements' => [
                'active' => Advertisement::active()->count(),
                'total_impressions' => (int) Advertisement::sum('impressions_count'),
                'total_clicks' => (int) Advertisement::sum('clicks_count'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function userAnalytics(?Carbon $startDate = null, ?Carbon $endDate = null, string $period = 'monthly'): array
    {
        [$start, $end] = $this->resolveDates($startDate, $endDate);

        return Cache::remember("analytics:users:{$start}:{$end}:{$period}", self::CACHE_TTL, function () use ($start, $end, $period): array {
            $roleCounts = TenantUser::query()
                ->join('roles', 'tenant_users.role_id', '=', 'roles.id')
                ->select('roles.name', DB::raw('count(*) as count'))
                ->groupBy('roles.name')
                ->pluck('count', 'name')
                ->toArray();

            $trend = $this->timeSeries(User::query(), 'created_at', $start, $end, $period);

            $previousStart = Carbon::parse($start)->sub($this->periodInterval($start, $end));
            $previousCount = User::whereBetween('created_at', [$previousStart, $start])->count();
            $currentCount = User::whereBetween('created_at', [$start, $end])->count();
            $growthRate = $previousCount > 0 ? round((($currentCount - $previousCount) / $previousCount) * 100, 2) : null;

            return [
                'total' => User::count(),
                'active' => User::where('account_status', 'active')->count(),
                'suspended' => User::where('account_status', 'suspended')->count(),
                'by_role' => $roleCounts,
                'new_in_period' => $currentCount,
                'growth_rate_percent' => $growthRate,
                'trend' => $trend,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function facilityAnalytics(?Carbon $startDate = null, ?Carbon $endDate = null, string $period = 'monthly'): array
    {
        [$start, $end] = $this->resolveDates($startDate, $endDate);

        return Cache::remember("analytics:facilities:{$start}:{$end}:{$period}", self::CACHE_TTL, fn (): array => [
            'total' => Facility::count(),
            'active' => Facility::where('is_active', true)->count(),
            'inactive' => Facility::where('is_active', false)->count(),
            'avg_courts_per_facility' => round((float) Facility::withCount('courts')->get()->avg('courts_count'), 1),
            'new_in_period' => Facility::whereBetween('created_at', [$start, $end])->count(),
            'trend' => $this->timeSeries(Facility::query(), 'created_at', $start, $end, $period),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function bookingAnalytics(?Carbon $startDate = null, ?Carbon $endDate = null, string $period = 'monthly'): array
    {
        [$start, $end] = $this->resolveDates($startDate, $endDate);

        return Cache::remember("analytics:bookings:{$start}:{$end}:{$period}", self::CACHE_TTL, function () use ($start, $end, $period): array {
            $byStatus = Booking::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $guestCount = Booking::whereNull('user_id')->whereBetween('created_at', [$start, $end])->count();
            $registeredCount = Booking::whereNotNull('user_id')->whereBetween('created_at', [$start, $end])->count();

            return [
                'total' => Booking::count(),
                'in_period' => Booking::whereBetween('created_at', [$start, $end])->count(),
                'by_status' => $byStatus,
                'guest_bookings' => $guestCount,
                'registered_bookings' => $registeredCount,
                'avg_value' => round((float) Booking::whereBetween('created_at', [$start, $end])->avg('total_amount'), 2),
                'trend' => $this->timeSeries(Booking::query(), 'created_at', $start, $end, $period),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function revenueAnalytics(?Carbon $startDate = null, ?Carbon $endDate = null, string $period = 'monthly'): array
    {
        [$start, $end] = $this->resolveDates($startDate, $endDate);

        return Cache::remember("analytics:revenue:{$start}:{$end}:{$period}", self::CACHE_TTL, function () use ($start, $end, $period): array {
            $bookingRevenue = (float) Payment::where('status', 'verified')
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');

            $subscriptionRevenue = (float) SubscriptionInvoice::where('status', 'paid')
                ->whereBetween('paid_at', [$start, $end])
                ->sum('amount');

            $pendingPayments = (float) Payment::where('status', 'pending')
                ->sum('amount');

            $mrr = (float) SubscriptionInvoice::where('status', 'paid')
                ->where('paid_at', '>=', now()->subMonth())
                ->sum('amount');

            return [
                'total_collected' => (float) Payment::where('status', 'verified')->sum('amount'),
                'booking_revenue' => $bookingRevenue,
                'subscription_revenue' => $subscriptionRevenue,
                'total_in_period' => $bookingRevenue + $subscriptionRevenue,
                'pending_payments' => $pendingPayments,
                'mrr' => $mrr,
                'arr' => $mrr * 12,
                'trend' => $this->revenueTrend($start, $end, $period),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function subscriptionAnalytics(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        [$start, $end] = $this->resolveDates($startDate, $endDate);

        return Cache::remember("analytics:subscriptions:{$start}:{$end}", self::CACHE_TTL, function () use ($start, $end): array {
            $byStatus = TenantSubscription::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $planDistribution = TenantSubscription::query()
                ->join('subscription_plans', 'tenant_subscriptions.subscription_plan_id', '=', 'subscription_plans.id')
                ->select('subscription_plans.name', DB::raw('count(*) as count'))
                ->whereIn('tenant_subscriptions.status', ['active', 'trialing'])
                ->groupBy('subscription_plans.name')
                ->pluck('count', 'name')
                ->toArray();

            $cancelledInPeriod = TenantSubscription::where('status', 'cancelled')
                ->whereBetween('cancelled_at', [$start, $end])
                ->count();
            $activeStart = TenantSubscription::whereIn('status', ['active', 'trialing'])
                ->where('starts_at', '<', $start)
                ->count();
            $churnRate = $activeStart > 0 ? round(($cancelledInPeriod / $activeStart) * 100, 2) : 0;

            return [
                'total' => TenantSubscription::count(),
                'by_status' => $byStatus,
                'plan_distribution' => $planDistribution,
                'new_in_period' => TenantSubscription::whereBetween('created_at', [$start, $end])->count(),
                'cancelled_in_period' => $cancelledInPeriod,
                'churn_rate_percent' => $churnRate,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function clubAnalytics(?Carbon $startDate = null, ?Carbon $endDate = null, string $period = 'monthly'): array
    {
        [$start, $end] = $this->resolveDates($startDate, $endDate);

        return Cache::remember("analytics:clubs:{$start}:{$end}:{$period}", self::CACHE_TTL, fn (): array => [
            'total' => Club::count(),
            'active' => Club::where('status', 'active')->count(),
            'total_memberships' => DB::table('club_members')->count(),
            'new_in_period' => Club::whereBetween('created_at', [$start, $end])->count(),
            'trend' => $this->timeSeries(Club::query(), 'created_at', $start, $end, $period),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function tournamentAnalytics(?Carbon $startDate = null, ?Carbon $endDate = null, string $period = 'monthly'): array
    {
        [$start, $end] = $this->resolveDates($startDate, $endDate);

        return Cache::remember("analytics:tournaments:{$start}:{$end}:{$period}", self::CACHE_TTL, function () use ($start, $end, $period): array {
            $byStatus = Tournament::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            return [
                'total' => Tournament::count(),
                'by_status' => $byStatus,
                'total_registrations' => DB::table('tournament_participants')->count(),
                'in_period' => Tournament::whereBetween('created_at', [$start, $end])->count(),
                'trend' => $this->timeSeries(Tournament::query(), 'created_at', $start, $end, $period),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function advertisementAnalytics(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        [$start, $end] = $this->resolveDates($startDate, $endDate);

        return Cache::remember("analytics:advertisements:{$start}:{$end}", self::CACHE_TTL, function (): array {
            $totalImpressions = (int) Advertisement::sum('impressions_count');
            $totalClicks = (int) Advertisement::sum('clicks_count');

            $topAds = Advertisement::orderByDesc('clicks_count')
                ->take(10)
                ->get(['id', 'title', 'placement', 'impressions_count', 'clicks_count']);

            $byPlacement = Advertisement::select('placement', DB::raw('sum(impressions_count) as impressions'), DB::raw('sum(clicks_count) as clicks'))
                ->groupBy('placement')
                ->get()
                ->keyBy('placement')
                ->toArray();

            return [
                'total_ads' => Advertisement::count(),
                'active_ads' => Advertisement::active()->count(),
                'total_impressions' => $totalImpressions,
                'total_clicks' => $totalClicks,
                'overall_ctr' => $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0,
                'top_ads' => $topAds,
                'by_placement' => $byPlacement,
            ];
        });
    }

    /**
     * @return array{string, string}
     */
    private function resolveDates(?Carbon $startDate, ?Carbon $endDate): array
    {
        $start = ($startDate ?? now()->startOfMonth())->toDateTimeString();
        $end = ($endDate ?? now())->toDateTimeString();

        return [$start, $end];
    }

    /**
     * @return list<array{date: string, count: int}>
     */
    private function timeSeries($query, string $column, string $start, string $end, string $period): array
    {
        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $format = match ($period) {
                'daily' => '%Y-%m-%d',
                'weekly' => '%Y-%W',
                'quarterly' => null,
                'yearly' => '%Y',
                default => '%Y-%m',
            };

            $groupExpr = $period === 'quarterly'
                ? DB::raw("(strftime('%Y', {$column}) || '-Q' || ((cast(strftime('%m', {$column}) as integer) + 2) / 3)) as date")
                : DB::raw("strftime('{$format}', {$column}) as date");
        } else {
            $format = match ($period) {
                'daily' => '%Y-%m-%d',
                'weekly' => '%Y-%u',
                'quarterly' => null,
                'yearly' => '%Y',
                default => '%Y-%m',
            };

            $groupExpr = $period === 'quarterly'
                ? DB::raw("CONCAT(YEAR({$column}), '-Q', QUARTER({$column})) as date")
                : DB::raw("DATE_FORMAT({$column}, '{$format}') as date");
        }

        return $query->clone()
            ->select($groupExpr, DB::raw('count(*) as count'))
            ->whereBetween($column, [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    /**
     * @return list<array{date: string, amount: float}>
     */
    private function revenueTrend(string $start, string $end, string $period): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $format = match ($period) {
                'daily' => '%Y-%m-%d',
                'weekly' => '%Y-%W',
                'yearly' => '%Y',
                default => '%Y-%m',
            };
            $dateExpr = DB::raw("strftime('{$format}', created_at) as date");
        } else {
            $format = match ($period) {
                'daily' => '%Y-%m-%d',
                'weekly' => '%Y-%u',
                'yearly' => '%Y',
                default => '%Y-%m',
            };
            $dateExpr = DB::raw("DATE_FORMAT(created_at, '{$format}') as date");
        }

        return Payment::where('status', 'verified')
            ->select($dateExpr, DB::raw('sum(amount) as amount'))
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    private function periodInterval(string $start, string $end): \DateInterval
    {
        return Carbon::parse($start)->diff(Carbon::parse($end));
    }
}
