<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        return $this->success([
            'users'           => $this->users(),
            'units'           => $this->units(),
            'bookings'        => $this->bookings(),
            'revenue'         => $this->revenue(),
            'occupancy_rate'  => $this->occupancyRate(),
            'monthly_revenue' => $this->monthlyRevenue(),
            'recent_requests' => $this->recentRequests(),
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
            'pending'   => (int) ($byStatus['pending'] ?? 0),
            'confirmed' => (int) ($byStatus['confirmed'] ?? 0),
            'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
        ];
    }

    /** @return array{total: float, this_month: float, currency: string} */
    private function revenue(): array
    {
        $confirmed = Booking::where('status', 'confirmed');

        return [
            'total'      => round((float) (clone $confirmed)->sum('total_amount'), 2),
            'this_month' => round((float) (clone $confirmed)
                ->where('created_at', '>=', now()->startOfMonth())
                ->sum('total_amount'), 2),
            'currency'   => 'SAR',
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

        $occupied = Booking::where('status', 'confirmed')
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->distinct('unit_id')
            ->count('unit_id');

        return (int) round(($occupied / $approved) * 100);
    }

    /**
     * Confirmed-booking revenue for the last 6 months (oldest → newest).
     *
     * @return array<int, array{month: string, label: string, total: float}>
     */
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
            $key  = $date->format('Y-m');
            $series[] = [
                'month' => $key,
                'label' => $months[$date->month - 1],
                'total' => round((float) ($rows[$key] ?? 0), 2),
            ];
        }

        return $series;
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
                'city'       => $unit->city,
                'name'       => $unit->owner?->name ?? '—',
                'type'       => $unit->owner?->hasRole('Company') ? 'Company' : 'Individual',
                'status'     => $unit->approval_status,
                'created_at' => $unit->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
