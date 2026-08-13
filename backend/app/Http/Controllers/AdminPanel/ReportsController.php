<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Models\Booking;
use App\Support\AdminPanel\Analytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports summary — BACKEND_SPEC §5.10. All series cover the requested range
 * (6m | 1y | all, default 1y). avgMonthlyRevenue = totalRevenue / months;
 * occupancyAverage = mean of occupancySeries. CSV/PDF export stays client-side.
 */
class ReportsController extends Controller
{
    public function __construct(private readonly Analytics $analytics) {}

    public function summary(Request $request): JsonResponse
    {
        $range  = $this->cleanParam($request->query('range')) ?? '1y';
        $months = $this->months($range);
        $since  = now()->subMonths($months - 1)->startOfMonth();

        $revenue        = Booking::query()->revenue()->where('created_at', '>=', $since);
        $grossSum       = (float) (clone $revenue)->sum('total_amount');
        $totalRevenue   = $this->money($grossSum);
        // VAT split (contract §5.4). Derived as total − taxes rather than by
        // summing `subtotal`, so it stays exact under BOTH pricing models and
        // still reconciles for the historical fee bookings.
        // Invariant: netRevenue + vatCollected === totalRevenue.
        $vatSum         = (float) (clone $revenue)->sum('taxes');
        $occupancy      = $this->analytics->occupancySeries($months);
        $occupancyAvg   = $occupancy !== [] ? (int) round(array_sum(array_column($occupancy, 'value')) / count($occupancy)) : 0;

        return response()->json([
            'totalRevenue'        => $totalRevenue,
            'netRevenue'          => $this->money($grossSum - $vatSum),
            'vatCollected'        => $this->money($vatSum),
            'totalCommission'     => $this->money($this->commissionSum((clone $revenue))),
            'totalBookings'       => Booking::where('created_at', '>=', $since)->count(),
            'avgMonthlyRevenue'   => $this->money($totalRevenue / $months),
            'revenueSeries'       => $this->analytics->revenueSeries($months),
            'revenueByCity'       => $this->analytics->revenueByCity($since),
            'bookingStatusSlices' => $this->analytics->bookingStatusSlices($since),
            'bookingVolume'       => $this->analytics->bookingVolume($months),
            'occupancySeries'     => $occupancy,
            'occupancyAverage'    => $occupancyAvg,
            'topPartners'         => $this->analytics->topPartners(5, $since),
        ]);
    }

    /** Range → number of months. `all` spans from the first booking (min 1). */
    private function months(string $range): int
    {
        return match ($range) {
            '6m'    => 6,
            'all'   => $this->monthsSinceFirstBooking(),
            default => 12, // 1y
        };
    }

    private function monthsSinceFirstBooking(): int
    {
        $first = Booking::min('created_at');

        if (! $first) {
            return 12;
        }

        // +1 so the first and current month are both inclusive; cap to keep the
        // series bounded on very old data.
        $months = (int) abs(now()->diffInMonths(\Illuminate\Support\Carbon::parse($first))) + 1;

        return max(1, min(60, $months));
    }
}
