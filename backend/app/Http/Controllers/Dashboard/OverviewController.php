<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\Booking;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard overview metrics (contract §3.1). Revenue is the partner share
 * only (98% — total minus the frozen 2% commission) from non-cancelled
 * bookings. Deltas/sparklines are derived on the frontend from the series.
 */
class OverviewController extends DashboardController
{
    public function show(Request $request): JsonResponse
    {
        $user    = $request->user();
        $unitIds = $user->units()->pluck('id');

        $nonCancelled = fn ($q) => $q->whereIn('status', [Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED]);

        // partner share = total - commission (fallback 2% for legacy rows).
        $shareExpr = 'COALESCE(SUM(total_amount - COALESCE(commission_amount, ROUND(total_amount * 0.02, 2))), 0)';

        $bookingsCount = Booking::whereIn('unit_id', $unitIds)->where($nonCancelled)->count();
        $totalRevenue  = (float) Booking::whereIn('unit_id', $unitIds)->where($nonCancelled)
            ->selectRaw("{$shareExpr} as v")->value('v');

        // 12-month series (oldest → newest), zero-filled.
        $start = CarbonImmutable::now()->startOfMonth()->subMonths(11);
        $rows = Booking::whereIn('unit_id', $unitIds)->where($nonCancelled)
            ->where('start_date', '>=', $start->toDateString())
            ->selectRaw("DATE_FORMAT(start_date, '%Y-%m') as ym")
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw("{$shareExpr} as amt")
            ->groupBy('ym')->pluck('amt', 'ym');
        $counts = Booking::whereIn('unit_id', $unitIds)->where($nonCancelled)
            ->where('start_date', '>=', $start->toDateString())
            ->selectRaw("DATE_FORMAT(start_date, '%Y-%m') as ym")
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('ym')->pluck('cnt', 'ym');

        $bookingsByMonth = $revenueByMonth = [];
        for ($i = 0; $i < 12; $i++) {
            $ym = $start->addMonths($i)->format('Y-m');
            $bookingsByMonth[] = ['month' => $ym, 'count' => (int) ($counts[$ym] ?? 0)];
            $revenueByMonth[]  = ['month' => $ym, 'amount' => round((float) ($rows[$ym] ?? 0), 2)];
        }

        $monthStart = CarbonImmutable::now()->startOfMonth();
        $thisMonthRevenue = (float) Booking::whereIn('unit_id', $unitIds)->where($nonCancelled)
            ->where('start_date', '>=', $monthStart->toDateString())
            ->selectRaw("{$shareExpr} as v")->value('v');

        return response()->json([
            'unitsCount'       => $user->units()->where('approval_status', '!=', 'draft')->count(),
            'bookingsCount'    => $bookingsCount,
            'totalRevenue'     => round($totalRevenue, 2),
            'bookingsByMonth'  => $bookingsByMonth,
            'revenueByMonth'   => $revenueByMonth,
            'thisMonthRevenue' => round($thisMonthRevenue, 2),
            'occupancyRate'    => $this->occupancy($unitIds, $monthStart),
            'hasRejectedUnit'  => $user->units()->where('approval_status', 'rejected')->exists(),
        ]);
    }

    /** % of booked nights over available nights across approved units this month. */
    private function occupancy($unitIds, CarbonImmutable $monthStart): int
    {
        $approvedCount = Unit::whereIn('id', $unitIds)->where('approval_status', 'approved')->count();
        if ($approvedCount === 0) {
            return 0;
        }

        $monthEnd  = $monthStart->endOfMonth();
        $daysInMonth = $monthStart->daysInMonth;
        $available = $approvedCount * $daysInMonth;

        // Sum booked nights that fall within this month (clamped to the window).
        $bookedNights = 0;
        $bookings = Booking::whereIn('unit_id', $unitIds)
            ->whereIn('status', [Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED])
            ->whereHas('unit', fn ($q) => $q->where('approval_status', 'approved'))
            ->where('start_date', '<=', $monthEnd->toDateString())
            ->where('end_date', '>', $monthStart->toDateString())
            ->get(['start_date', 'end_date']);

        foreach ($bookings as $b) {
            $from = $b->start_date->greaterThan($monthStart) ? $b->start_date : $monthStart;
            $to   = $b->end_date->lessThan($monthEnd->addDay()) ? $b->end_date : $monthEnd->addDay();
            $bookedNights += max(0, $from->diffInDays($to));
        }

        return (int) round(min(100, ($bookedNights / $available) * 100));
    }
}
