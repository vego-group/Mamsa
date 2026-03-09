<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\BookingsExport;
use App\Exports\BookingsSummaryExport;

class ReportsController extends Controller
{
    use AuthorizesRequests;

    private function buildFilteredQuery(Request $request)
    {
        $this->authorize('viewAny', Booking::class);

        $q        = trim((string) $request->get('q', ''));
        $status   = $request->get('status');
        $unitId   = $request->get('unit_id');
        $dateFrom = $request->get('from');
        $dateTo   = $request->get('to');

        $query = Booking::query()
            ->with(['unit.owner', 'customer'])
            ->when($q !== '', function ($qb) use ($q) {
                $qb->where(function ($sub) use ($q) {
                    $sub->whereHas('unit', function ($u) use ($q) {
                            $u->where('name', 'like', "%{$q}%")
                              ->orWhere('code', 'like', "%{$q}%");
                        })
                        ->orWhereHas('customer', function ($c) use ($q) {
                            $c->where('name', 'like', "%{$q}%")
                              ->orWhere('email', 'like', "%{$q}%");
                        });
                });
            })
            ->when($status, fn($qb) => $qb->where('status', $status))
            ->when($unitId, fn($qb)   => $qb->where('unit_id', $unitId))
            ->when($dateFrom, fn($qb) => $qb->whereDate('start_date', '>=', $dateFrom))
            ->when($dateTo, fn($qb)   => $qb->whereDate('end_date', '<=', $dateTo));

        if ($request->user()->hasRole('admin') && ! $request->user()->hasRole('super_admin')) {
            $query->whereHas('unit', fn($u) => $u->where('user_id', $request->user()->id));
        }

        return $query;
    }

    public function index(Request $request)
    {
        $query    = $this->buildFilteredQuery($request)->orderByDesc('id');
        $bookings = $query->paginate(12)->withQueryString();

        // بطاقات
        $totalBookings   = (clone $query)->count();
        $sumAmount       = (clone $query)->sum('total_amount');
        $countNew        = (clone $query)->where('status', 'new')->count();
        $countConfirmed  = (clone $query)->where('status', 'confirmed')->count();
        $countCompleted  = (clone $query)->where('status', 'completed')->count();
        $countCancelled  = (clone $query)->where('status', 'cancelled')->count();

        // وحدات للفلاتر
        $unitsList = Unit::query()
            ->when($request->user()->hasRole('admin') && ! $request->user()->hasRole('super_admin'),
                fn($u) => $u->where('user_id', $request->user()->id))
            ->orderBy('name')
            ->get(['id','name','code']);

        // تجميع شهري (آخر 12 شهر)
        $end   = Carbon::now()->startOfMonth();
        $start = (clone $end)->subMonths(11);

        $driver  = DB::getDriverName();
        $dateExp = $driver === 'sqlite'
            ? "strftime('%Y-%m-01', start_date)"
            : "DATE_FORMAT(start_date, '%Y-%m-01')";

        $monthly = (clone $this->buildFilteredQuery($request))
            ->selectRaw("$dateExp as month_key")
            ->selectRaw('COUNT(*) as bookings_count')
            ->selectRaw('COALESCE(SUM(total_amount),0) as revenue_sum')
            ->whereNotNull('start_date')
            ->whereDate('start_date', '>=', $start->toDateString())
            ->whereDate('start_date', '<=', $end->copy()->endOfMonth()->toDateString())
            ->groupBy('month_key')
            ->orderBy('month_key')
            ->get()
            ->keyBy('month_key');

        $labels = [];
        $bookingsSeries = [];
        $revenueSeries  = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $key = $cursor->format('Y-m-01');
            $labels[]         = $cursor->format('Y-m');
            $bookingsSeries[] = (int)   optional($monthly->get($key))->bookings_count ?? 0;
            $revenueSeries[]  = (float) optional($monthly->get($key))->revenue_sum ?? 0;
            $cursor->addMonth();
        }

        return view('admin.reports.index', [
            'bookings'       => $bookings,
            'unitsList'      => $unitsList,
            'q'              => $request->get('q', ''),
            'status'         => $request->get('status'),
            'unitId'         => $request->get('unit_id'),
            'dateFrom'       => $request->get('from'),
            'dateTo'         => $request->get('to'),
            'totalBookings'  => $totalBookings,
            'sumAmount'      => $sumAmount,
            'countNew'       => $countNew,
            'countConfirmed' => $countConfirmed,
            'countCompleted' => $countCompleted,
            'countCancelled' => $countCancelled,
            'labels'         => $labels,
            'bookingsSeries' => $bookingsSeries,
            'revenueSeries'  => $revenueSeries,
        ]);
    }

    // ===== CSV (موجود سابقًا) =====
    public function exportBookingsCsv(Request $request)
    {
        $query = $this->buildFilteredQuery($request)->orderByDesc('id');

        $filename = 'bookings_' . now()->format('Ymd_His') . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename={$filename}",
        ];

        $callback = function () use ($query) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // BOM

            fputcsv($handle, [
                'ID','الوحدة','كود الوحدة','مالك الوحدة','الحاجز',
                'الحالة','من','إلى','المبلغ'
            ]);

            $query->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $b) {
                    fputcsv($handle, [
                        $b->id,
                        optional($b->unit)->name,
                        optional($b->unit)->code,
                        optional(optional($b->unit)->owner)->name,
                        optional($b->customer)->name,
                        $b->status,
                        optional($b->start_date)->format('Y-m-d'),
                        optional($b->end_date)->format('Y-m-d'),
                        $b->total_amount,
                    ]);
                }
            });

            fclose($handle);
        };

        return Response::stream($callback, 200, $headers);
    }

    public function exportSummaryCsv(Request $request)
    {
        $end   = Carbon::now()->startOfMonth();
        $start = (clone $end)->subMonths(11);

        $driver  = DB::getDriverName();
        $dateExp = $driver === 'sqlite'
            ? "strftime('%Y-%m', start_date)"
            : "DATE_FORMAT(start_date, '%Y-%m')";

        $monthly = (clone $this->buildFilteredQuery($request))
            ->selectRaw("$dateExp as ym")
            ->selectRaw('COUNT(*) as bookings_count')
            ->selectRaw('COALESCE(SUM(total_amount),0) as revenue_sum')
            ->whereNotNull('start_date')
            ->whereDate('start_date', '>=', $start->toDateString())
            ->whereDate('start_date', '<=', $end->copy()->endOfMonth()->toDateString())
            ->groupBy('ym')
            ->orderBy('ym')
            ->get()
            ->keyBy('ym');

        $filename = 'bookings_summary_' . now()->format('Ymd_His') . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename={$filename}",
        ];

        $callback = function () use ($start, $end, $monthly) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['الشهر (YYYY-MM)', 'عدد الحجوزات', 'إجمالي المبالغ']);

            $cursor = $start->copy();
            while ($cursor->lte($end)) {
                $ym  = $cursor->format('Y-m');
                $row = $monthly->get($ym);
                fputcsv($handle, [
                    $ym,
                    $row->bookings_count ?? 0,
                    $row->revenue_sum    ?? 0,
                ]);
                $cursor->addMonth();
            }

            fclose($handle);
        };

        return Response::stream($callback, 200, $headers);
    }

    // ===== Excel =====
    public function exportBookingsExcel(Request $request)
    {
        $query = $this->buildFilteredQuery($request)->orderByDesc('id')->with(['unit.owner','customer']);
        $filename = 'bookings_' . now()->format('Ymd_His') . '.xlsx';
        return Excel::download(new BookingsExport($query), $filename);
    }

    public function exportSummaryExcel(Request $request)
    {
        // نحضر نفس تجميع الملخص (آخر 12 شهر)
        $end   = Carbon::now()->startOfMonth();
        $start = (clone $end)->subMonths(11);

        $driver  = DB::getDriverName();
        $dateExp = $driver === 'sqlite'
            ? "strftime('%Y-%m', start_date)"
            : "DATE_FORMAT(start_date, '%Y-%m')";

        $monthly = (clone $this->buildFilteredQuery($request))
            ->selectRaw("$dateExp as ym")
            ->selectRaw('COUNT(*) as bookings_count')
            ->selectRaw('COALESCE(SUM(total_amount),0) as revenue_sum')
            ->whereNotNull('start_date')
            ->whereDate('start_date', '>=', $start->toDateString())
            ->whereDate('start_date', '<=', $end->copy()->endOfMonth()->toDateString())
            ->groupBy('ym')
            ->orderBy('ym')
            ->get()
            ->keyBy('ym');

        // ترتيب الأشهر
        $rows = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $ym  = $cursor->format('Y-m');
            $row = $monthly->get($ym);
            $rows[] = [
                'month'          => $ym,
                'bookings_count' => (int)   ($row->bookings_count ?? 0),
                'revenue_sum'    => (float) ($row->revenue_sum ?? 0),
            ];
            $cursor->addMonth();
        }

        $filename = 'bookings_summary_' . now()->format('Ymd_His') . '.xlsx';
        return Excel::download(new BookingsSummaryExport($rows), $filename);
    }

    // ===== PDF (بهويّة ممسـى) =====
    public function exportBookingsPdf(Request $request)
    {
        // نجلب كل النتائج المصفاة (نحط حد أمان 2000 صف)
        $rows = $this->buildFilteredQuery($request)
            ->with(['unit.owner','customer'])
            ->orderByDesc('id')
            ->limit(2000)
            ->get();

        $data = [
            'title'   => 'تقرير الحجوزات (تفصيلي)',
            'rows'    => $rows,
            'filters' => [
                'q'       => $request->get('q'),
                'status'  => $request->get('status'),
                'unit_id' => $request->get('unit_id'),
                'from'    => $request->get('from'),
                'to'      => $request->get('to'),
            ],
            'generated_at' => now()->format('Y-m-d H:i'),
        ];

        $pdf = Pdf::loadView('admin.reports.pdf.bookings', $data)->setPaper('a4', 'portrait');
        return $pdf->download('bookings_' . now()->format('Ymd_His') . '.pdf');
    }

    public function exportSummaryPdf(Request $request)
    {
        $end   = Carbon::now()->startOfMonth();
        $start = (clone $end)->subMonths(11);

        $driver  = DB::getDriverName();
        $dateExp = $driver === 'sqlite'
            ? "strftime('%Y-%m', start_date)"
            : "DATE_FORMAT(start_date, '%Y-%m')";

        $monthly = (clone $this->buildFilteredQuery($request))
            ->selectRaw("$dateExp as ym")
            ->selectRaw('COUNT(*) as bookings_count')
            ->selectRaw('COALESCE(SUM(total_amount),0) as revenue_sum')
            ->whereNotNull('start_date')
            ->whereDate('start_date', '>=', $start->toDateString())
            ->whereDate('start_date', '<=', $end->copy()->endOfMonth()->toDateString())
            ->groupBy('ym')
            ->orderBy('ym')
            ->get()
            ->keyBy('ym');

        $rows = [];
        $totalCount = 0;
        $totalRevenue = 0.0;

        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $ym  = $cursor->format('Y-m');
            $row = $monthly->get($ym);
            $count  = (int)   ($row->bookings_count ?? 0);
            $amount = (float) ($row->revenue_sum ?? 0);
            $rows[] = ['month' => $ym, 'bookings_count' => $count, 'revenue_sum' => $amount];
            $totalCount  += $count;
            $totalRevenue+= $amount;
            $cursor->addMonth();
        }

        $data = [
            'title'         => 'ملخص الحجوزات الشهري (آخر 12 شهر)',
            'rows'          => $rows,
            'totalCount'    => $totalCount,
            'totalRevenue'  => $totalRevenue,
            'generated_at'  => now()->format('Y-m-d H:i'),
        ];

        $pdf = Pdf::loadView('admin.reports.pdf.summary', $data)->setPaper('a4', 'portrait');
        return $pdf->download('bookings_summary_' . now()->format('Ymd_His') . '.pdf');
    }
}