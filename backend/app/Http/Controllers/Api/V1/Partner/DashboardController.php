<?php

namespace App\Http\Controllers\Api\V1\Partner;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $unitIds = $user->units()->pluck('id');

        $totalUnits     = $user->units()->count();
        $pendingUnits   = $user->units()->where('approval_status', 'pending')->count();
        $approvedUnits  = $user->units()->where('approval_status', 'approved')->count();

        $totalBookings    = Booking::whereIn('unit_id', $unitIds)->count();
        $confirmedBookings = Booking::whereIn('unit_id', $unitIds)->where('status', 'confirmed')->count();

        $totalRevenue = Booking::whereIn('unit_id', $unitIds)
            ->where('status', 'confirmed')
            ->sum('total_amount');

        return response()->json([
            'units' => [
                'total'    => $totalUnits,
                'pending'  => $pendingUnits,
                'approved' => $approvedUnits,
            ],
            'bookings' => [
                'total'     => $totalBookings,
                'confirmed' => $confirmedBookings,
            ],
            'revenue' => [
                'total'    => round($totalRevenue, 2),
                'currency' => 'SAR',
            ],
        ]);
    }
}
