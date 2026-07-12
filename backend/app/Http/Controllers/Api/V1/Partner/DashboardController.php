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

        // Partner money = rental subtotal + cleaning fee; Mamsa keeps the
        // service fee and remits taxes, so neither belongs to the partner.
        $earnings = Booking::whereIn('unit_id', $unitIds)
            ->where('status', 'confirmed')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as total')
            ->selectRaw('COALESCE(SUM(subtotal + cleaning_fee), 0) as gross')
            ->selectRaw('COALESCE(SUM(commission_amount), 0) as commission')
            ->first();

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
                'total'      => round((float) $earnings->total, 2),
                'gross'      => round((float) $earnings->gross, 2),
                'commission' => round((float) $earnings->commission, 2),
                'net'        => round((float) ($earnings->gross - $earnings->commission), 2),
                'currency'   => 'SAR',
            ],
        ]);
    }
}
