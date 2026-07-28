<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\User;
use App\Support\AdminPanel\Analytics;
use App\Support\AdminPanel\CancellationPresenter;
use App\Support\AdminPanel\UnitPresenter;
use Illuminate\Http\JsonResponse;

/**
 * Dashboard summary — BACKEND_SPEC §5.3. KPIs + month-over-month deltas +
 * the series/embedded lists the home screen renders. All money is live from
 * confirmed bookings; commission is the frozen 2%.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly Analytics $analytics,
        private readonly UnitPresenter $units,
        private readonly CancellationPresenter $cancellations,
    ) {}

    public function summary(): JsonResponse
    {
        [$monthStart, $prevStart, $prevEnd] = [
            now()->startOfMonth(),
            now()->subMonth()->startOfMonth(),
            now()->subMonth()->endOfMonth(),
        ];

        $confirmed        = Booking::where('status', 'confirmed');
        $confirmedRevenue = (float) (clone $confirmed)->sum('total_amount');
        $confirmedCount   = (clone $confirmed)->count();

        $revThisMonth = (float) Booking::where('status', 'confirmed')->where('created_at', '>=', $monthStart)->sum('total_amount');
        $revPrevMonth = (float) Booking::where('status', 'confirmed')->whereBetween('created_at', [$prevStart, $prevEnd])->sum('total_amount');

        return response()->json([
            'totalUsers'         => User::role('User', 'web')->count(),
            'platformCommission' => $this->money((clone $confirmed)->sum('commission_amount')),
            'totalBookings'      => Booking::count(),
            'activePartners'     => $this->activePartners(),
            'pendingRequests'    => Unit::where('approval_status', 'pending')->count(),
            'monthlyGrowth'      => $this->pctDelta($revThisMonth, $revPrevMonth),
            'avgBookingValue'    => $confirmedCount > 0 ? $this->money($confirmedRevenue / $confirmedCount) : 0.0,
            'deltas'             => [
                'totalUsers'         => $this->createdDelta(User::role('User', 'web'), $monthStart, $prevStart, $prevEnd),
                'platformCommission' => $this->pctDelta(
                    (float) Booking::where('status', 'confirmed')->where('created_at', '>=', $monthStart)->sum('commission_amount'),
                    (float) Booking::where('status', 'confirmed')->whereBetween('created_at', [$prevStart, $prevEnd])->sum('commission_amount'),
                ),
                'totalBookings'      => $this->createdDelta(Booking::query(), $monthStart, $prevStart, $prevEnd),
                'activePartners'     => $this->createdDelta(User::role(['Individual', 'Company'], 'web')->whereHas('partnerDetail'), $monthStart, $prevStart, $prevEnd),
            ],
            'revenueSeries'           => $this->analytics->revenueSeries(12),
            'bookingStatusSlices'     => $this->analytics->bookingStatusSlices(),
            'revenueByCity'           => $this->analytics->revenueByCity(),
            'weeklyBookings'          => $this->analytics->weeklyBookings(),
            'latestPendingRequests'   => $this->latestPendingRequests(),
            'recentHostCancellations' => $this->recentHostCancellations(),
        ]);
    }

    private function activePartners(): int
    {
        return User::role(['Individual', 'Company'], 'web')
            ->where('is_active', true)
            ->whereHas('partnerDetail', fn ($q) => $q->where('status', PartnerDetail::STATUS_APPROVED))
            ->count();
    }

    /** % change of rows created this month vs the previous month. */
    private function createdDelta(\Illuminate\Database\Eloquent\Builder $query, $monthStart, $prevStart, $prevEnd): float
    {
        $cur  = (clone $query)->where('created_at', '>=', $monthStart)->count();
        $prev = (clone $query)->whereBetween('created_at', [$prevStart, $prevEnd])->count();

        return $this->pctDelta((float) $cur, (float) $prev);
    }

    /** Up to 5 pending units, newest submittedAt first. */
    private function latestPendingRequests(): array
    {
        return Unit::with('owner.partnerDetail')->where('approval_status', 'pending')
            ->orderByDesc('updated_at')->limit(5)->get()
            ->map(fn (Unit $u) => $this->units->approvalRow($u))->all();
    }

    /** Up to 5 host cancellations, newest first. */
    private function recentHostCancellations(): array
    {
        return Booking::with(['user', 'unit.owner', 'payment', 'refunds'])
            ->where('status', 'cancelled')
            ->where(fn ($q) => $q->where('cancelled_by', '!=', 'customer')->orWhereNull('cancelled_by'))
            ->orderByDesc('cancelled_at')->limit(5)->get()
            ->map(fn (Booking $b) => $this->cancellations->row($b))->all();
    }
}
