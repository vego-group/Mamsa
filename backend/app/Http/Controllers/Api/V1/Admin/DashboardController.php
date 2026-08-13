<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        return $this->success([
            'users'             => $this->users(),
            'units'             => $this->units(),
            'bookings'          => $this->bookings(),
            'revenue'           => $this->revenue(),
            'occupancy_rate'    => $this->occupancyRate(),
            'monthly_revenue'   => $this->monthlyRevenue(),
            'recent_requests'   => $this->recentRequests(),
            // ── additive fields for the redesigned dashboard ──
            'active_partners'   => $this->activePartners(),
            'avg_booking_value' => $this->avgBookingValue(),
            'monthly_growth'    => $this->monthlyGrowth(),
            'changes'           => $this->changes(),
            'revenue_by_city'   => $this->revenueByCity(),
            'weekly_bookings'   => $this->weeklyBookings(),
        ]);
    }

    /** @return array<string,int> */
    private function users(): array
    {
        return [
            'total'     => User::count(),
            'partners'  => User::role(['Individual', 'Company'])->count(),
            'customers' => User::role('User')->count(),
        ];
    }

    /** @return array<string,int> */
    private function units(): array
    {
        $byStatus = Unit::query()
            ->selectRaw('approval_status, COUNT(*) as c')
            ->groupBy('approval_status')
            ->pluck('c', 'approval_status');

        return [
            'total'    => (int) $byStatus->sum(),
            'draft'    => (int) ($byStatus['draft'] ?? 0),
            'pending'  => (int) ($byStatus['pending'] ?? 0),
            'approved' => (int) ($byStatus['approved'] ?? 0),
            'rejected' => (int) ($byStatus['rejected'] ?? 0),
        ];
    }

    /** @return array<string,int> */
    private function bookings(): array
    {
        $byStatus = Booking::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'total'     => (int) $byStatus->sum(),
            // Response key stays `pending` for the existing /api/v1 consumers;
            // the DB value is now `pending_payment` (renamed 2026-08-13).
            'pending'   => (int) ($byStatus[Booking::STATUS_PENDING] ?? 0),
            'confirmed' => (int) ($byStatus['confirmed'] ?? 0),
            'completed' => (int) ($byStatus['completed'] ?? 0),
            'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
        ];
    }

    /** @return array{total: float, this_month: float, commission: float, commission_this_month: float, currency: string} */
    private function revenue(): array
    {
        // Revenue = paid stays (confirmed + completed); commission = frozen amount
        // where captured, else 2% of subtotal (historical bookings read ~0).
        $revenue   = Booking::query()->revenue();
        $thisMonth = (clone $revenue)->where('created_at', '>=', now()->startOfMonth());
        $comm      = fn ($q) => round((float) $q->sum(DB::raw(Booking::commissionExpr())), 2);

        return [
            'total'                 => round((float) (clone $revenue)->sum('total_amount'), 2),
            'this_month'            => round((float) (clone $thisMonth)->sum('total_amount'), 2),
            'commission'            => $comm(clone $revenue),
            'commission_this_month' => $comm(clone $thisMonth),
            'currency'              => 'SAR',
        ];
    }

    /**
     * Approved units occupied today (active confirmed booking) as a percentage
     * of all approved units.
     */
    private function occupancyRate(): int
    {
        $approved = Unit::where('approval_status', 'approved')->count();
        if ($approved === 0) {
            return 0;
        }

        $occupied = Booking::query()->revenue()
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->distinct('unit_id')
            ->count('unit_id');

        return (int) round(($occupied / $approved) * 100);
    }

    /**
     * Confirmed-booking revenue + Mamsa commission for the last 12 months
     * (oldest → newest). The frontend slices the last 3/6/12 for its toggle and
     * localizes the month label from the `month` (Y-m) key.
     *
     * @return array<int, array{month: string, label: string, total: float, commission: float}>
     */
    private function monthlyRevenue(): array
    {
        $rows = Booking::query()->revenue()
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, SUM(total_amount) as total, SUM(".Booking::commissionExpr().") as commission")
            ->groupBy('ym')
            ->get()
            ->keyBy('ym');

        $months = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

        $series = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $key  = $date->format('Y-m');
            $series[] = [
                'month'      => $key,
                'label'      => $months[$date->month - 1],
                'total'      => round((float) ($rows[$key]->total ?? 0), 2),
                'commission' => round((float) ($rows[$key]->commission ?? 0), 2),
            ];
        }

        return $series;
    }

    /** Active partner accounts (Individual/Company, not deactivated). */
    private function activePartners(): int
    {
        return User::role(['Individual', 'Company'])->where('is_active', true)->count();
    }

    /** Average revenue-booking value (rounded). */
    private function avgBookingValue(): float
    {
        return round((float) Booking::query()->revenue()->avg('total_amount'), 2);
    }

    /** Revenue this month vs last month, as a signed percentage. */
    private function monthlyGrowth(): float
    {
        $thisMonth = (float) Booking::query()->revenue()
            ->where('created_at', '>=', now()->startOfMonth())->sum('total_amount');
        $lastMonth = (float) Booking::query()->revenue()
            ->whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->startOfMonth()])
            ->sum('total_amount');

        return $this->pct($thisMonth, $lastMonth);
    }

    /**
     * Month-over-month change (%) for each headline KPI — powers the ↑/↓ badges.
     *
     * @return array{users: float, commission: float, bookings: float, partners: float, avg_value: float}
     */
    private function changes(): array
    {
        $tmStart = now()->startOfMonth();
        $lmStart = now()->subMonth()->startOfMonth();

        $countBetween = fn ($query, $from, $to) => (int) (clone $query)->whereBetween('created_at', [$from, $to])->count();
        $countFrom    = fn ($query, $from) => (int) (clone $query)->where('created_at', '>=', $from)->count();

        $users    = User::query();
        $partners = User::role(['Individual', 'Company']);
        $bookings = Booking::query();
        $revenue  = Booking::query()->revenue();

        $commThis = (float) (clone $revenue)->where('created_at', '>=', $tmStart)->sum(DB::raw(Booking::commissionExpr()));
        $commLast = (float) (clone $revenue)->whereBetween('created_at', [$lmStart, $tmStart])->sum(DB::raw(Booking::commissionExpr()));

        $avgThis = (float) (clone $revenue)->where('created_at', '>=', $tmStart)->avg('total_amount');
        $avgLast = (float) (clone $revenue)->whereBetween('created_at', [$lmStart, $tmStart])->avg('total_amount');

        return [
            'users'      => $this->pct($countFrom($users, $tmStart), $countBetween($users, $lmStart, $tmStart)),
            'commission' => $this->pct($commThis, $commLast),
            'bookings'   => $this->pct($countFrom($bookings, $tmStart), $countBetween($bookings, $lmStart, $tmStart)),
            'partners'   => $this->pct($countFrom($partners, $tmStart), $countBetween($partners, $lmStart, $tmStart)),
            'avg_value'  => $this->pct($avgThis, $avgLast),
        ];
    }

    /**
     * Top 5 cities by confirmed-booking revenue (rental unit's city).
     *
     * @return array<int, array{city: string, total: float}>
     */
    private function revenueByCity(): array
    {
        return Booking::query()->revenue()
            ->join('units', 'units.id', '=', 'bookings.unit_id')
            ->selectRaw('units.city as city, SUM(bookings.total_amount) as total')
            ->groupBy('units.city')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['city' => $r->city ?: '—', 'total' => round((float) $r->total, 2)])
            ->all();
    }

    /**
     * Bookings per weekday for the last 7 days (Sun → Sat), for the bar chart.
     *
     * @return array<int, array{day: string, count: int}>
     */
    private function weeklyBookings(): array
    {
        // MySQL DAYOFWEEK: 1 = Sunday … 7 = Saturday
        $rows = Booking::where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('DAYOFWEEK(created_at) as dow, COUNT(*) as c')
            ->groupBy('dow')
            ->pluck('c', 'dow');

        $keys = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $out = [];
        foreach ($keys as $idx => $key) {
            $out[] = ['day' => $key, 'count' => (int) ($rows[$idx + 1] ?? 0)];
        }

        return $out;
    }

    /** Signed percentage change; +100 when growing from zero. */
    private function pct(float $current, float $previous): float
    {
        if ($previous <= 0.0) {
            return $current > 0.0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Latest partner units awaiting review, with owner + partner type.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentRequests(): array
    {
        return Unit::with('owner.roles')
            ->where('approval_status', 'pending')
            ->latest()
            ->limit(6)
            ->get()
            ->map(fn (Unit $unit) => [
                'id'         => $unit->id,
                'code'       => $unit->code,
                'unit_name'  => $unit->unit_name,
                'unit_type'  => $unit->unit_type,
                'city'       => $unit->city,
                'name'       => $unit->owner?->name ?? '—',
                'type'       => $unit->owner?->hasRole('Company') ? 'Company' : 'Individual',
                'status'     => $unit->approval_status,
                'created_at' => $unit->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
