<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Support\Sql;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Review;
use App\Models\Unit;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        return $this->success([
            'kpis'             => $this->kpis(),
            'monthly_revenue'  => $this->monthlyRevenue(),
            'units_by_status'  => $this->unitsByStatus(),
            'bookings_by_city' => $this->bookingsByCity(),
            'revenue_by_city'  => $this->revenueByCity(),
            'booking_status'   => $this->bookingStatus(),
            'top_units'        => $this->topUnits(),
        ]);
    }

    /** @return array<string, mixed> */
    private function kpis(): array
    {
        // Revenue-bearing = paid stays (confirmed + completed). Counting only
        // 'confirmed' drops every finished stay and reads near-zero.
        $revenue = Booking::query()->revenue();

        $avgNights = (float) (clone $revenue)
            ->selectRaw(Sql::avgDays().' as a')
            ->value('a');

        $totalRevenue = round((float) (clone $revenue)->sum('total_amount'), 2);
        $activeMonths = $this->activeMonths();

        return [
            'total_revenue'    => $totalRevenue,
            // Mamsa's 2% cut: frozen amount where captured, else 2% of subtotal.
            'total_commission' => round((float) (clone $revenue)->sum(DB::raw(Booking::commissionExpr())), 2),
            'total_bookings'   => Booking::count(),
            'avg_monthly_revenue' => round($totalRevenue / max(1, $activeMonths), 2),
            'occupancy_rate'   => $this->occupancyRate(),
            'avg_nights'       => round($avgNights, 1),
            'avg_rating'       => round((float) Review::avg('rating'), 1),
            'reviews_count'    => Review::count(),
        ];
    }

    /** Distinct months that actually have revenue — so the average isn't diluted by /12. */
    private function activeMonths(): int
    {
        return max(1, (int) Booking::query()->revenue()
            ->selectRaw('COUNT(DISTINCT '.Sql::ym('created_at').') as m')->value('m'));
    }

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

    /** @return array<int, array{month: string, label: string, total: float, commission: float}> */
    private function monthlyRevenue(): array
    {
        $rows = Booking::query()->revenue()
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->selectRaw(Sql::ym('created_at')." as ym, SUM(total_amount) as total, SUM(".Booking::commissionExpr().") as commission")
            ->groupBy('ym')
            ->get()
            ->keyBy('ym');

        $months = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

        $series = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $key = $date->format('Y-m');
            $series[] = [
                'month'      => $key,
                'label'      => $months[$date->month - 1],
                'total'      => round((float) ($rows[$key]->total ?? 0), 2),
                'commission' => round((float) ($rows[$key]->commission ?? 0), 2),
            ];
        }

        return $series;
    }

    /** @return array<int, array{city: string, total: float}> */
    private function revenueByCity(): array
    {
        return Booking::query()
            ->whereIn('bookings.status', Booking::REVENUE_STATUSES)
            ->join('units', 'units.id', '=', 'bookings.unit_id')
            ->whereNotNull('units.city')
            ->selectRaw('units.city as city, SUM(bookings.total_amount) as total')
            ->groupBy('units.city')
            ->orderByDesc('total')
            ->limit(6)
            ->get()
            ->map(fn ($r) => ['city' => $r->city, 'total' => round((float) $r->total, 2)])
            ->all();
    }

    /** @return array<string, int> */
    private function bookingStatus(): array
    {
        $c = Booking::query()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return [
            'confirmed' => (int) ($c['confirmed'] ?? 0),
            'completed' => (int) ($c['completed'] ?? 0),
            // Response key stays `pending` for existing /api/v1 consumers; the
            // DB value is now `pending_payment` (renamed 2026-08-13).
            'pending'   => (int) ($c[Booking::STATUS_PENDING] ?? 0),
            'cancelled' => (int) ($c['cancelled'] ?? 0),
        ];
    }

    /** @return array<string, int> */
    private function unitsByStatus(): array
    {
        $byStatus = Unit::query()
            ->selectRaw('approval_status, COUNT(*) as c')
            ->groupBy('approval_status')
            ->pluck('c', 'approval_status');

        return [
            'total'    => (int) $byStatus->sum(),
            'approved' => (int) ($byStatus['approved'] ?? 0),
            'pending'  => (int) ($byStatus['pending'] ?? 0),
            'rejected' => (int) ($byStatus['rejected'] ?? 0),
            'draft'    => (int) ($byStatus['draft'] ?? 0),
        ];
    }

    /** @return array<int, array{city: string, count: int}> */
    private function bookingsByCity(): array
    {
        return Booking::query()
            ->join('units', 'units.id', '=', 'bookings.unit_id')
            ->selectRaw('units.city as city, COUNT(*) as count')
            ->whereNotNull('units.city')
            ->groupBy('units.city')
            ->orderByDesc('count')
            ->limit(6)
            ->get()
            ->map(fn ($r) => ['city' => $r->city, 'count' => (int) $r->count])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function topUnits(): array
    {
        return Unit::query()
            ->withCount('bookings')
            ->withSum(['bookings as revenue' => fn ($q) => $q->whereIn('status', Booking::REVENUE_STATUSES)], 'total_amount')
            // HAVING with no GROUP BY is a MySQL extension; sqlite rejects it.
            // "has at least one booking" is what this means, and whereHas says
            // it in a form both drivers accept.
            ->whereHas('bookings')
            ->orderByDesc('bookings_count')
            ->limit(5)
            ->get()
            ->map(fn (Unit $u) => [
                'name'     => $u->unit_name,
                'city'     => $u->city,
                'bookings' => (int) $u->bookings_count,
                'revenue'  => round((float) ($u->revenue ?? 0), 2),
            ])
            ->all();
    }
}
