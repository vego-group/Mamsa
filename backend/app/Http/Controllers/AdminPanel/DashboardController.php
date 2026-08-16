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

    /**
     * GET /admin/cities → [{ key, en, ar }]
     *
     * The vocabulary for every `city=` filter. `units.city` stores Arabic free
     * text (`مكة المكرمة`), so a client hardcoding either language is guessing
     * at our data — and a spelling variant fails as an empty list rather than
     * an error. Populate the filter from this and neither side keeps a list.
     */
    public function cities(): JsonResponse
    {
        return response()->json(\App\Support\City::all());
    }

    public function summary(): JsonResponse
    {
        [$monthStart, $prevStart, $prevEnd] = [
            now()->startOfMonth(),
            now()->subMonth()->startOfMonth(),
            now()->subMonth()->endOfMonth(),
        ];

        // Revenue = paid stays (confirmed + completed); commission = 2% of subtotal.
        $revenue        = Booking::query()->revenue();
        $revenueTotal   = (float) (clone $revenue)->sum('total_amount');
        $revenueCount   = (clone $revenue)->count();

        $revThisMonth = (float) Booking::query()->revenue()->where('created_at', '>=', $monthStart)->sum('total_amount');
        $revPrevMonth = (float) Booking::query()->revenue()->whereBetween('created_at', [$prevStart, $prevEnd])->sum('total_amount');

        return response()->json([
            'totalUsers'         => User::role('User', 'web')->count(),
            'platformCommission' => $this->money($this->commissionSum((clone $revenue))),
            'totalBookings'      => Booking::count(),
            'activePartners'     => $this->activePartners(),
            'pendingRequests'    => Unit::where('approval_status', 'pending')->count(),
            'monthlyGrowth'      => $this->pctDelta($revThisMonth, $revPrevMonth),
            'avgBookingValue'    => $revenueCount > 0 ? $this->money($revenueTotal / $revenueCount) : 0.0,
            'deltas'             => [
                'totalUsers'         => $this->createdDelta(User::role('User', 'web'), $monthStart, $prevStart, $prevEnd),
                'platformCommission' => $this->pctDelta(
                    $this->commissionSum(Booking::query()->revenue()->where('created_at', '>=', $monthStart)),
                    $this->commissionSum(Booking::query()->revenue()->whereBetween('created_at', [$prevStart, $prevEnd])),
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
