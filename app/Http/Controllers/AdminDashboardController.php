<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user    = $request->user();
        $isSuper = $user->hasRole('super_admin');

        // المستخدمون (إجمالي)
        $usersCount = User::count();

        // الوحدات (super_admin = الكل, admin = وحداته فقط)
        if (class_exists(\App\Models\Unit::class)) {
            $unitsQuery = \App\Models\Unit::query();
            if (! $isSuper) {
                $unitsQuery->where('user_id', $user->id);
            }
            $unitsCount = $unitsQuery->count();
        } else {
            $unitsCount = 0;
        }

        // الحجوزات + الإيرادات + المخططات (آخر 12 شهر فقط)
        $bookingsCount = 0;
        $revenueTotal  = 0.0;
        $labels        = [];
        $bookDataset   = [];
        $revDataset    = [];

        if (class_exists(\App\Models\Booking::class)) {
            // قاعدة الاستعلام
            $base = \App\Models\Booking::query();

            // admin: حجوزات وحداته فقط
            if (! $isSuper) {
                $base->whereHas('unit', fn($u) => $u->where('user_id', $user->id));
            }

            // أرقام إجمالية
            $bookingsCount = (clone $base)->count();
            $revenueTotal  = (float) ((clone $base)->sum('total_amount') ?? 0);

            // حدود الفترة (آخر 12 شهر)
            $end   = now()->startOfMonth();           // أول يوم من الشهر الحالي
            $start = (clone $end)->subMonthsNoOverflow(11); // قبل 11 شهر (12 شهر إجمالي)

            // دالة التاريخ حسب نوع قاعدة البيانات
            $driver  = DB::getDriverName(); // mysql | sqlite | pgsql ...
            $dateExp = $driver === 'sqlite'
                ? "strftime('%Y-%m', start_date)"
                : "DATE_FORMAT(start_date, '%Y-%m')";

            // تجميع شهري (12 صف كحد أقصى)
            $monthly = (clone $base)
                ->selectRaw("$dateExp as ym")
                ->selectRaw('COUNT(*) as bookings_count')
                ->selectRaw('COALESCE(SUM(total_amount),0) as revenue_sum')
                ->whereNotNull('start_date')
                ->whereBetween('start_date', [
                    $start->toDateString(),
                    $end->copy()->endOfMonth()->toDateString()
                ])
                ->groupBy('ym')
                ->orderBy('ym')
                ->get()
                ->keyBy('ym');

            // نبني المحاور عبر for ثابت (12 تكرار فقط)
            for ($i = 11; $i >= 0; $i--) {
                $m  = $end->copy()->subMonthsNoOverflow($i);
                $ym = $m->format('Y-m');
                $labels[] = $ym;

                $row = $monthly->get($ym);
                $bookDataset[] = $row ? (int) $row->bookings_count : 0;
                $revDataset[]  = $row ? (float) $row->revenue_sum  : 0.0;
            }
        } else {
            // fallback إن ما فيه موديل Bookings
            $labels      = ['1','2','3','4','5','6','7','8','9','10','11','12'];
            $bookDataset = array_fill(0, 12, 0);
            $revDataset  = array_fill(0, 12, 0);
        }

        return view('admin.dashboard', [
            'usersCount'     => $usersCount,
            'unitsCount'     => $unitsCount,
            'bookingsCount'  => $bookingsCount,
            'revenueTotal'   => $revenueTotal,
            'revLabels'      => $labels,
            'revDataset'     => $revDataset,
            'bookLabels'     => $labels,
            'bookDataset'    => $bookDataset,
            'isSuper'        => $isSuper,
        ]);
    }
}