<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Review;
use App\Models\Unit;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

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
            'top_units'        => $this->topUnits(),
        ]);
    }

    /** @return array<string, mixed> */
    private function kpis(): array
    {
        $confirmed = Booking::where('status', 'confirmed');

        $avgNights = (float) (clone $confirmed)
            ->selectRaw('AVG(DATEDIFF(end_date, start_date)) as a')
            ->value('a');

        return [
            'total_revenue'  => round((float) (clone $confirmed)->sum('total_amount'), 2),
            'occupancy_rate' => $this->occupancyRate(),
            'avg_nights'     => round($avgNights, 1),
            'avg_rating'     => round((float) Review::avg('rating'), 1),
            'reviews_count'  => Review::count(),
        ];
    }

    private function occupancyRate(): int
    {
        $approved = Unit::where('approval_status', 'approved')->count();
        if ($approved === 0) {
            return 0;
        }

        $occupied = Booking::where('status', 'confirmed')
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->distinct('unit_id')
            ->count('unit_id');

        return (int) round(($occupied / $approved) * 100);
    }

    /** @return array<int, array{month: string, label: string, total: float}> */
    private function monthlyRevenue(): array
    {
        $rows = Booking::where('status', 'confirmed')
            ->where('created_at', '>=', now()->subMonths(5)->startOfMonth())
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, SUM(total_amount) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $months = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

        $series = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $series[] = [
                'month' => $date->format('Y-m'),
                'label' => $months[$date->month - 1],
                'total' => round((float) ($rows[$date->format('Y-m')] ?? 0), 2),
            ];
        }

        return $series;
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
            ->withSum(['bookings as revenue' => fn ($q) => $q->where('status', 'confirmed')], 'total_amount')
            ->having('bookings_count', '>', 0)
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
