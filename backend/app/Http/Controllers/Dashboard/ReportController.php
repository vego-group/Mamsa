<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports (contract §7). Gross revenue = sum of paid totals (non-cancelled)
 * in range; commission = 2%; netProfit = gross − commission (a real SAR
 * amount). from/to only — shortcuts are computed on the frontend.
 */
class ReportController extends DashboardController
{
    public function summary(Request $request): JsonResponse
    {
        [$from, $to, $unitIds] = $this->range($request);

        $base = fn () => Booking::whereIn('unit_id', $unitIds)
            ->whereIn('status', [Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED])
            ->whereBetween('start_date', [$from->toDateString(), $to->toDateString()]);

        $gross      = (float) $base()->sum('total_amount');
        $commission = (float) $base()->selectRaw('COALESCE(SUM(COALESCE(commission_amount, ROUND(total_amount*0.02,2))),0) as v')->value('v');
        $count      = $base()->count();

        return response()->json([
            'grossRevenue'    => round($gross, 2),
            'bookingsCount'   => $count,
            'commission'      => round($commission, 2),
            'netProfit'       => round($gross - $commission, 2),
            'revenueByMonth'  => $this->series($base(), 'amount'),
            'bookingsByMonth' => $this->series($base(), 'count'),
            'perUnit'         => $this->perUnit($base()),
        ]);
    }

    public function export(Request $request): Response
    {
        [$from, $to, $unitIds] = $this->range($request);
        $format = strtolower((string) $request->query('format', 'pdf'));

        $rows = Booking::whereIn('unit_id', $unitIds)
            ->whereIn('status', [Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED])
            ->whereBetween('start_date', [$from->toDateString(), $to->toDateString()])
            ->with('unit:id,unit_name')
            ->orderBy('start_date')
            ->get();

        $filename = 'mamsa-report-'.$from->toDateString().'_'.$to->toDateString();

        // xlsx alias → CSV (opens natively in Excel; no binary dependency).
        if (in_array($format, ['xlsx', 'csv'], true)) {
            return $this->csv($rows, $filename.'.csv');
        }

        // pdf → server-rendered PDF via mpdf (proper Arabic shaping + RTL).
        $tempDir = storage_path('app/mpdf');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($tempDir);

        $mpdf = new \Mpdf\Mpdf([
            'mode'             => 'utf-8',
            'format'           => 'A4',
            'directionality'   => 'rtl',
            'autoScriptToLang' => true,
            'autoLangToFont'   => true,
            'tempDir'          => $tempDir,
        ]);
        $mpdf->WriteHTML($this->reportHtml($rows, $from, $to));

        return response($mpdf->Output($filename.'.pdf', \Mpdf\Output\Destination::STRING_RETURN), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
        ]);
    }

    /* ---- helpers ---- */

    /** @return array{0:CarbonImmutable,1:CarbonImmutable,2:\Illuminate\Support\Collection} */
    private function range(Request $request): array
    {
        $data = $this->validated($request, [
            'from' => ['required', 'date_format:Y-m-d'],
            'to'   => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [
            CarbonImmutable::parse($data['from']),
            CarbonImmutable::parse($data['to']),
            $request->user()->units()->pluck('id'),
        ];
    }

    private function series($query, string $kind): array
    {
        $expr = $kind === 'amount'
            ? 'COALESCE(SUM(total_amount),0) as v'
            : 'COUNT(*) as v';

        $rows = $query->selectRaw("DATE_FORMAT(start_date,'%Y-%m') as ym")
            ->selectRaw($expr)->groupBy('ym')->pluck('v', 'ym');

        return $rows->map(fn ($v, $ym) => [
            'month' => $ym,
            ($kind === 'amount' ? 'amount' : 'count') => $kind === 'amount' ? round((float) $v, 2) : (int) $v,
        ])->values()->all();
    }

    private function perUnit($query): array
    {
        return $query->selectRaw('unit_id')
            ->selectRaw('COUNT(*) as bookings')
            ->selectRaw('COALESCE(SUM(total_amount),0) as revenue')
            ->groupBy('unit_id')
            ->with('unit:id,unit_name')
            ->get()
            ->map(fn ($r) => [
                'unitId'   => 'u_'.$r->unit_id,
                'unitName' => $r->unit?->unit_name,
                'bookings' => (int) $r->bookings,
                'revenue'  => round((float) $r->revenue, 2),
            ])->all();
    }

    private function csv($rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders Arabic
            fputcsv($out, ['Code', 'Unit', 'Guest', 'Check-in', 'Check-out', 'Nights', 'Total (SAR)', 'Commission', 'Net', 'Status']);
            foreach ($rows as $b) {
                $commission = (float) ($b->commission_amount ?? round($b->total_amount * 0.02, 2));
                fputcsv($out, [
                    'BK-'.$b->id,
                    $b->unit?->unit_name,
                    $b->user?->name,
                    $b->start_date?->toDateString(),
                    $b->end_date?->toDateString(),
                    $b->nights,
                    number_format((float) $b->total_amount, 2, '.', ''),
                    number_format($commission, 2, '.', ''),
                    number_format((float) $b->total_amount - $commission, 2, '.', ''),
                    $b->status,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    private function reportHtml($rows, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $gross = $rows->sum('total_amount');
        $commission = $rows->sum(fn ($b) => (float) ($b->commission_amount ?? round($b->total_amount * 0.02, 2)));
        $body = '';
        foreach ($rows as $b) {
            $c = (float) ($b->commission_amount ?? round($b->total_amount * 0.02, 2));
            $body .= '<tr><td>BK-'.$b->id.'</td><td>'.e($b->unit?->unit_name).'</td><td>'.e($b->user?->name)
                .'</td><td>'.$b->start_date?->toDateString().'</td><td>'.$b->end_date?->toDateString().'</td>'
                .'<td>'.number_format((float) $b->total_amount, 2).'</td><td>'.number_format($c, 2).'</td>'
                .'<td>'.number_format((float) $b->total_amount - $c, 2).'</td></tr>';
        }

        return '<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8">'
            .'<title>تقرير ممسى</title><style>body{font-family:sans-serif;padding:24px}'
            .'h1{font-size:20px}table{width:100%;border-collapse:collapse;margin-top:16px;font-size:12px}'
            // NB: no @page/@media-print rule — mpdf sets A4 via its format
            // option, and an @page size rule here makes it paginate wildly.
            .'th,td{border:1px solid #ccc;padding:6px;text-align:right}th{background:#163c24;color:#fff}'
            .'.tot{margin-top:16px;font-weight:bold}</style></head><body>'
            .'<h1>تقرير الأداء — ممسى</h1><p>الفترة: '.$from->toDateString().' إلى '.$to->toDateString().'</p>'
            .'<table><thead><tr><th>الكود</th><th>الوحدة</th><th>الضيف</th><th>الوصول</th><th>المغادرة</th>'
            .'<th>الإجمالي</th><th>العمولة</th><th>الصافي</th></tr></thead><tbody>'.$body.'</tbody></table>'
            .'<p class="tot">الإجمالي: '.number_format((float) $gross, 2).' ر.س · العمولة: '
            .number_format((float) $commission, 2).' ر.س · الصافي: '.number_format((float) $gross - $commission, 2).' ر.س</p>'
            .'</body></html>';
    }
}
