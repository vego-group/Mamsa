<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cancellations & refunds overview (Figma "Cancellations" screen). Reads from
 * cancelled bookings + their payment's refunded_amount; the financial impact is
 * Mamsa's lost commission on those bookings.
 */
class CancellationController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        // ── paginated list ──
        $query = Booking::query()->with(['user', 'unit', 'payment'])->where('status', 'cancelled');

        $by = $request->query('by');
        if ($by === 'guest') {
            $query->where('cancelled_by', 'customer');
        } elseif ($by === 'host') {
            $query->where(fn ($q) => $q->where('cancelled_by', '!=', 'customer')->orWhereNull('cancelled_by'));
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
                $q->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('unit', fn ($u) => $u->where('unit_name', 'like', "%{$search}%"));
            });
        }

        $rows = $query->latest('cancelled_at')->paginate(20);

        return response()->json([
            'data'    => $rows->getCollection()->map(fn (Booking $b) => $this->mapRow($b))->all(),
            'meta'    => [
                'current_page' => $rows->currentPage(),
                'last_page'    => $rows->lastPage(),
                'total'        => $rows->total(),
            ],
            'counts'         => $this->counts(),
            'summary'        => $this->summary(),
            'trend'          => $this->trend(),
            'refund_status'  => $this->refundStatusBuckets(),
            'high_risk'      => $this->highRiskPartners(),
        ]);
    }

    /** @return array<string, mixed> */
    private function mapRow(Booking $b): array
    {
        $refunded = (float) ($b->payment?->refunded_amount ?? 0);

        return [
            'id'            => $b->id,
            'code'          => sprintf('CXL-%03d', $b->id),
            'booking_code'  => sprintf('BKG-%d', $b->id),
            'guest_name'    => $b->user?->name ?? '—',
            'cancelled_by'  => $b->cancelled_by === 'customer' ? 'guest' : 'host',
            'property'      => $b->unit?->unit_name ?? '—',
            'city'          => $b->unit?->city,
            'date'          => $b->cancelled_at?->toIso8601String(),
            'refund'        => round($refunded, 2),
            // The frozen split, so no client reconstructs it from a
            // VAT-inclusive total at today's rate. snake_case here to match the
            // rest of this v1 surface; the admin panel uses camelCase.
            'net_base'      => round((float) $b->subtotal, 2),
            'commission'    => round((float) $b->commission_amount, 2),
            'partner_share' => round((float) $b->partner_share, 2),
            // Lost Mamsa commission on the cancelled booking (shown negative).
            'impact'        => -round((float) $b->commission_amount, 2),
            'refund_status' => $this->refundStatus($refunded, (float) $b->total_amount),
        ];
    }

    private function refundStatus(float $refunded, float $amount): string
    {
        if ($refunded <= 0) {
            return 'no_refund';
        }

        return $refunded + 0.01 >= $amount ? 'refunded' : 'partial';
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $all = Booking::where('status', 'cancelled')->count();
        $guest = Booking::where('status', 'cancelled')->where('cancelled_by', 'customer')->count();

        return ['all' => $all, 'guest' => $guest, 'host' => $all - $guest];
    }

    /** @return array<string, int|float> */
    private function summary(): array
    {
        $cancelled = Booking::where('status', 'cancelled');

        $totalRefunds = (float) Booking::where('bookings.status', 'cancelled')
            ->join('payments', 'payments.booking_id', '=', 'bookings.id')
            ->sum('payments.refunded_amount');

        return [
            'total_refunds'    => round($totalRefunds, 2),
            // Per-row via the shared expression, so a booking that predates the
            // frozen columns still counts instead of reading as zero.
            'financial_impact' => round((float) (clone $cancelled)->sum(\Illuminate\Support\Facades\DB::raw(\App\Models\Booking::commissionExpr())), 2),
            'host_cancellations' => (clone $cancelled)->where(fn ($q) => $q->where('cancelled_by', '!=', 'customer')->orWhereNull('cancelled_by'))->count(),
        ];
    }

    /**
     * Guest vs host cancellations per month (last 6 months, oldest → newest).
     *
     * @return array<int, array{month: string, guest: int, host: int}>
     */
    private function trend(): array
    {
        $rows = Booking::where('status', 'cancelled')
            ->where('cancelled_at', '>=', now()->subMonths(5)->startOfMonth())
            ->selectRaw("DATE_FORMAT(cancelled_at, '%Y-%m') as ym,
                SUM(CASE WHEN cancelled_by = 'customer' THEN 1 ELSE 0 END) as guest,
                SUM(CASE WHEN cancelled_by = 'customer' THEN 0 ELSE 1 END) as host")
            ->groupBy('ym')
            ->get()
            ->keyBy('ym');

        $out = [];
        for ($i = 5; $i >= 0; $i--) {
            $key = now()->subMonths($i)->format('Y-m');
            $out[] = [
                'month' => $key,
                'guest' => (int) ($rows[$key]->guest ?? 0),
                'host'  => (int) ($rows[$key]->host ?? 0),
            ];
        }

        return $out;
    }

    /** @return array<string, int> */
    private function refundStatusBuckets(): array
    {
        $buckets = ['refunded' => 0, 'partial' => 0, 'no_refund' => 0, 'pending' => 0];

        Booking::where('status', 'cancelled')->with('payment')->get()->each(function (Booking $b) use (&$buckets) {
            $buckets[$this->refundStatus((float) ($b->payment?->refunded_amount ?? 0), (float) $b->total_amount)]++;
        });

        return $buckets;
    }

    /**
     * Partners with the most cancellations on their units (top 3), with rate.
     *
     * @return array<int, array<string, mixed>>
     */
    private function highRiskPartners(): array
    {
        return User::query()
            ->role(['Individual', 'Company'])
            ->whereHas('partnerDetail')
            ->with('partnerDetail')
            ->withCount(['unitBookings as bookings_count'])
            ->withCount(['unitBookings as cancellations_count' => fn ($q) => $q->where('bookings.status', 'cancelled')])
            ->addSelect(['city' => \App\Models\Unit::query()->select('city')->whereColumn('units.user_id', 'users.id')->latest()->limit(1)])
            ->having('cancellations_count', '>', 0)
            ->orderByDesc('cancellations_count')
            ->limit(3)
            ->get()
            ->map(fn (User $u) => [
                'name'          => $u->name,
                'city'          => $u->city,
                'type'          => $u->partnerDetail?->type ?? 'individual',
                'cancellations' => (int) $u->cancellations_count,
                'rate'          => $u->bookings_count > 0 ? round(($u->cancellations_count / $u->bookings_count) * 100, 1) : 0.0,
            ])
            ->all();
    }
}
