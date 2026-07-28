<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use App\Services\MoyasarService;
use App\Support\AdminPanel\CancellationPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cancellations & refunds — BACKEND_SPEC §5.9. impact is negative (platform
 * loss = lost 2% commission); stats.financialImpact is the positive total.
 */
class CancellationsController extends Controller
{
    private const HIGH_RISK_RATE = 15.0;

    public function __construct(
        private readonly MoyasarService $moyasar,
        private readonly CancellationPresenter $presenter,
    ) {}

    /** Correlated refunded-amount for a booking row (avoids join duplication). */
    private const REFUNDED = 'COALESCE((SELECT p.refunded_amount FROM payments p WHERE p.booking_id = bookings.id LIMIT 1), 0)';

    private const SORT = [
        'at'           => 'cancelled_at',
        'bookingTotal' => 'total_amount',
    ];

    public function index(Request $request): JsonResponse
    {
        $args  = $this->listArgs($request);
        $query = $this->baseQuery();

        if ($by = $this->cleanParam($request->query('cancelledBy'))) {
            $by === 'guest'
                ? $query->where('cancelled_by', 'customer')
                : $query->where(fn ($q) => $q->where('cancelled_by', '!=', 'customer')->orWhereNull('cancelled_by'));
        }
        if ($rf = $this->cleanParam($request->query('refundStatus'))) {
            $this->applyRefundStatus($query, $rf);
        }
        if ($partnerId = $this->cleanParam($request->query('partnerId'))) {
            $query->whereHas('unit', fn ($u) => $u->where('user_id', $partnerId));
        }
        if ($args['search'] !== null) {
            $s = $args['search'];
            $query->where(function ($q) use ($s) {
                if (ctype_digit($s)) {
                    $q->orWhere('id', (int) $s);
                }
                $q->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$s}%"))
                  ->orWhereHas('unit', fn ($u) => $u->where('unit_name', 'like', "%{$s}%"));
            });
        }

        $page = $this->queryList($query, $args, [], self::SORT, ['cancelled_at', 'desc']);

        return $this->items($page, fn (Booking $b) => $this->presenter->row($b));
    }

    public function stats(): JsonResponse
    {
        $total = Booking::where('status', 'cancelled')->count();
        $guest = Booking::where('status', 'cancelled')->where('cancelled_by', 'customer')->count();
        $host  = $total - $guest;

        $totalRefunds = (float) Booking::where('bookings.status', 'cancelled')
            ->join('payments', 'payments.booking_id', '=', 'bookings.id')
            ->sum('payments.refunded_amount');

        return response()->json([
            'total'            => $total,
            'byGuest'          => $guest,
            'byHost'           => $host,
            'totalRefunds'     => $this->money($totalRefunds),
            'financialImpact'  => $this->money($this->commissionSum(Booking::where('status', 'cancelled'))),
            'hostCancellations'=> $host,
            'refundBreakdown'  => $this->refundBreakdown($total),
            'trend'            => $this->trend(),
        ]);
    }

    /** GET /admin/cancellations/high-risk-partners — flagged only, most cancellations first. */
    public function highRiskPartners(): JsonResponse
    {
        $yearAgo = now()->subYear();

        $rows = User::query()->role(['Individual', 'Company'], 'web')
            ->whereHas('partnerDetail')->with('partnerDetail')
            ->withCount(['unitBookings as bookings_12m' => fn ($q) => $q->where('bookings.created_at', '>=', $yearAgo)])
            ->withCount(['unitBookings as cancellations_12m' => fn ($q) => $q->where('bookings.status', 'cancelled')->where('bookings.created_at', '>=', $yearAgo)])
            ->addSelect(['city' => Unit::query()->select('city')->whereColumn('units.user_id', 'users.id')->latest()->limit(1)])
            ->get()
            ->map(function (User $u) {
                $b    = (int) $u->bookings_12m;
                $rate = $b > 0 ? round(((int) $u->cancellations_12m / $b) * 100, 1) : 0.0;

                return [
                    'partnerId'     => (string) $u->id,
                    'name'          => $u->name ?? '',
                    'city'          => $u->city ?? '',
                    'type'          => $u->partnerDetail?->type ?? 'individual',
                    'cancellations' => (int) $u->cancellations_12m,
                    'rate'          => $rate,
                ];
            })
            ->filter(fn ($r) => $r['rate'] >= self::HIGH_RISK_RATE && $r['cancellations'] > 0)
            ->sortByDesc('cancellations')
            ->values()
            ->all();

        return response()->json($rows);
    }

    /**
     * POST /admin/cancellations/:id/retry-refund — re-attempt a `failed` refund
     * via Moyasar. 409 if there is no refund in the failed state. `:id` is the
     * cancelled booking id (a cancellation == a cancelled booking in this model).
     */
    public function retryRefund(string $id): JsonResponse
    {
        $booking = Booking::with(['payment', 'refunds'])->where('status', 'cancelled')->find($id);

        if (! $booking) {
            $this->fail('NOT_FOUND', 'الإلغاء غير موجود', 404);
        }

        $refund = $booking->refunds->firstWhere('status', 'failed');

        if (! $refund) {
            $this->fail('CONFLICT', 'لا يوجد استرداد فاشل لإعادة المحاولة', 409);
        }

        $payment = $booking->payment;
        $amount  = (float) $refund->amount;

        // Simulated when Moyasar isn't configured or the payment never hit the
        // gateway; otherwise re-post the refund and let the webhook confirm it.
        $simulated = $this->isTestMode() || ! $payment?->moyasar_id;

        try {
            $gateway = $simulated ? null : $this->moyasar->refund($payment->moyasar_id, (int) round($amount * 100));
        } catch (\Throwable $e) {
            report($e);
            $this->fail('REFUND_FAILED', 'تعذّر تنفيذ الاسترداد عبر بوابة الدفع، حاول لاحقاً', 502);
        }

        $refund->update([
            // Gateway-accepted refunds settle via webhook → 'pending' until then;
            // simulated (no gateway) is immediate. NB: the refunds enum is
            // pending|succeeded|failed (no 'processing').
            'status'            => $gateway ? 'pending' : 'succeeded',
            'moyasar_refund_id' => $gateway['id'] ?? $refund->moyasar_refund_id,
            'moyasar_response'  => $gateway ?? ['retried' => true, 'simulated' => true],
        ]);

        // A failed refund never moved money; on a successful retry reflect the
        // returned amount (capped so we can never over-refund the payment).
        if ($payment) {
            $payment->increment('refunded_amount', min($amount, $payment->refundableAmount()));
        }

        return $this->ok();
    }

    /* ---------- helpers ---------- */

    private function isTestMode(): bool
    {
        return blank(config('moyasar.secret_key'));
    }

    private function baseQuery(): Builder
    {
        return Booking::query()->with(['user', 'unit.owner', 'payment', 'refunds'])->where('status', 'cancelled');
    }

    private function applyRefundStatus(Builder $query, string $rf): void
    {
        $r = self::REFUNDED;
        match ($rf) {
            'none'     => $query->whereRaw("{$r} <= 0"),
            'refunded' => $query->whereRaw("{$r} >= bookings.total_amount"),
            'partial'  => $query->whereRaw("{$r} > 0 AND {$r} < bookings.total_amount"),
            'failed'   => $query->whereHas('refunds', fn ($q) => $q->where('status', 'failed')),
            default    => null,
        };
    }

    /** All four RefundStatus keys, summing to total. */
    private function refundBreakdown(int $total): array
    {
        $buckets = ['refunded' => 0, 'partial' => 0, 'none' => 0, 'failed' => 0];

        Booking::where('status', 'cancelled')->with(['payment', 'refunds'])->get()->each(function (Booking $b) use (&$buckets) {
            $buckets[$this->presenter->refundStatusOf($b, (float) ($b->payment?->refunded_amount ?? 0))]++;
        });

        return $buckets;
    }

    /** Guest vs host per month, last 6 months (oldest → newest). */
    private function trend(): array
    {
        $ym = $this->ymSql('cancelled_at');
        $rows = Booking::where('status', 'cancelled')
            ->where('cancelled_at', '>=', now()->subMonths(5)->startOfMonth())
            ->selectRaw("{$ym} as ym,
                SUM(CASE WHEN cancelled_by = 'customer' THEN 1 ELSE 0 END) as guest,
                SUM(CASE WHEN cancelled_by = 'customer' THEN 0 ELSE 1 END) as host")
            ->groupBy('ym')->get()->keyBy('ym');

        $out = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = now()->subMonths($i);
            $out[] = [
                'label' => $m->format('M'),
                'guest' => (int) ($rows[$m->format('Y-m')]->guest ?? 0),
                'host'  => (int) ($rows[$m->format('Y-m')]->host ?? 0),
            ];
        }

        return $out;
    }
}
