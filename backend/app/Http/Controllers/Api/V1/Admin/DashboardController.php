<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'users'    => User::count(),
            'units'    => [
                'total'    => Unit::count(),
                'pending'  => Unit::where('approval_status', 'pending')->count(),
                'approved' => Unit::where('approval_status', 'approved')->count(),
            ],
            'bookings' => [
                'total'     => Booking::count(),
                'confirmed' => Booking::where('status', 'confirmed')->count(),
            ],
            'revenue'  => [
                'total'    => round((float) Booking::where('status', 'confirmed')->sum('total_amount'), 2),
                'currency' => 'SAR',
            ],
        ]);
    }
}
