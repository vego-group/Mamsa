<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Unit;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user    = $request->user();
        $isSuper = $user->hasRole('SuperAdmin');

        /*
        |--------------------------------------------------------------------------
        | 1) عدد المستخدمين
        |--------------------------------------------------------------------------
        */
        $usersCount = User::count();


        /*
        |--------------------------------------------------------------------------
        | 2) عدد الوحدات + عدد الوحدات Pending
        |--------------------------------------------------------------------------
        */
       $unitsQuery = Unit::where('approval_status', 'approved');

if (!$isSuper) {
    $unitsQuery->where('user_id', $user->id);
}

$unitsCount = $unitsQuery->count();

        // pending فقط للسوبر
        $pendingUnitsCount = $isSuper
            ? Unit::where('approval_status', 'pending')->count()
            : 0;


        /*
        |--------------------------------------------------------------------------
        | 3) الحجوزات + الإيرادات
        |--------------------------------------------------------------------------
        */
        $bookingsBase = Booking::query();

        if (!$isSuper) {
            $bookingsBase->whereHas('unit', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        }

        $bookingsCount = (clone $bookingsBase)->count();
        $revenueTotal  = (float) ((clone $bookingsBase)->sum('total_amount') ?? 0);


        /*
        |--------------------------------------------------------------------------
        | 4) المخططات (آخر 12 شهر)
        |--------------------------------------------------------------------------
        */
        $labels      = [];
        $revDataset  = [];
        $bookDataset = [];

        $end   = now()->startOfMonth();
        $start = (clone $end)->subMonthsNoOverflow(11);

        $driver  = DB::getDriverName();
        $dateExp = $driver === 'sqlite'
            ? "strftime('%Y-%m', start_date)"
            : "DATE_FORMAT(start_date, '%Y-%m')";

        $monthly = (clone $bookingsBase)
            ->selectRaw("$dateExp as ym")
            ->selectRaw("COUNT(*) as bookings_count")
            ->selectRaw("COALESCE(SUM(total_amount), 0) as revenue_sum")
            ->whereBetween('start_date', [
                $start->toDateString(),
                $end->copy()->endOfMonth()->toDateString()
            ])
            ->groupBy('ym')
            ->orderBy('ym')
            ->get()
            ->keyBy('ym');

        for ($i = 11; $i >= 0; $i--) {
            $m  = $end->copy()->subMonthsNoOverflow($i);
            $ym = $m->format('Y-m');
            $labels[] = $ym;

            $row = $monthly->get($ym);
            $bookDataset[] = $row ? (int) $row->bookings_count : 0;
            $revDataset[]  = $row ? (float) $row->revenue_sum  : 0.0;
        }


        /*
        |--------------------------------------------------------------------------
        | 5) أحدث الوحدات + أحدث الحجوزات
        |--------------------------------------------------------------------------
        */

        // أحدث 5 وحدات
        $lastUnits = Unit::latest()
            ->take(5)
            ->get(['id', 'unit_name', 'approval_status', 'created_at']);

        // أحدث 5 حجوزات
        $lastBookings = Booking::with(['unit','customer'])
            ->latest()
            ->take(5)
            ->get();


        /*
        |--------------------------------------------------------------------------
        | 6) تمرير القيم للواجهة
        |--------------------------------------------------------------------------
        */

        return view('admin.dashboard', [
            'isSuper'            => $isSuper,
            'usersCount'         => $usersCount,
            'unitsCount'         => $unitsCount,
            'pendingUnitsCount'  => $pendingUnitsCount,
            'bookingsCount'      => $bookingsCount,
            'revenueTotal'       => $revenueTotal,
            'revLabels'          => $labels,
            'revDataset'         => $revDataset,
            'bookLabels'         => $labels,
            'bookDataset'        => $bookDataset,
            'lastUnits'          => $lastUnits,
            'lastBookings'       => $lastBookings,
        ]);
    }
}
