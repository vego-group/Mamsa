<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Models\Booking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bookings — BACKEND_SPEC §5.8 (read-only for admin).
 *
 * Money is VAT-inclusive: `total` is gross, commission is 2% of the VAT-exclusive
 * base, and partnerShare is the frozen per-booking column — never total minus
 * commission, which would pay the partner the VAT. policySnapshot is frozen at
 * payment time.
 */
class BookingsController extends Controller
{
    private const SORT = [
        'total'     => 'total_amount',
        'checkIn'   => 'start_date',
        'createdAt' => 'created_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $args  = $this->listArgs($request);
        $query = Booking::query()->with(['unit.owner', 'user', 'payment']);

        if ($status = $this->cleanParam($request->query('status'))) {
            $query->where('status', $status); // DB values are the spec literals since 2026-08-13
        }
        if ($unitId = $this->cleanParam($request->query('unitId'))) {
            $query->where('unit_id', $unitId);
        }
        if ($userId = $this->cleanParam($request->query('userId'))) {
            $query->where('user_id', $userId);
        }
        if ($city = $this->cleanParam($request->query('city'))) {
            $query->whereHas('unit', fn ($u) => $u->where('city', $city));
        }
        if ($partnerId = $this->cleanParam($request->query('partnerId'))) {
            $query->whereHas('unit', fn ($u) => $u->where('user_id', $partnerId));
        }
        if ($from = $this->cleanParam($request->query('from'))) {
            $query->whereDate('start_date', '>=', $from);
        }
        if ($to = $this->cleanParam($request->query('to'))) {
            $query->whereDate('start_date', '<=', $to);
        }
        if ($args['search'] !== null) {
            $s     = $args['search'];
            $id    = $this->codeTerm($s);      // accepts BKG-0231 as well as 231
            $phone = $this->phoneTerm($s);

            $query->where(function ($q) use ($s, $id, $phone) {
                // Code first: an admin copies BKG-0231 off the row, and that
                // string exists in no column — it is derived from the id.
                $id !== null
                    ? $q->where('bookings.id', $id)
                    : $q->whereRaw('1 = 0');

                $q->orWhereHas('user', function ($u) use ($s, $phone) {
                    $u->where('name', 'like', "%{$s}%");
                    $phone !== null
                        ? $u->orWhere('phone', 'like', "%{$phone}")
                        : $u->orWhere('phone', 'like', "%{$s}%");
                })
                    ->orWhereHas('unit', fn ($u) => $u->where('unit_name', 'like', "%{$s}%")
                        ->orWhereHas('owner', fn ($o) => $o->where('name', 'like', "%{$s}%")));
            });
        }

        $page = $this->queryList($query, $args, [], self::SORT, ['created_at', 'desc']);

        return $this->items($page, fn (Booking $b) => $this->row($b));
    }

    /** GET /admin/bookings/counts — per-status counts; the four sum to `all`. */
    public function counts(): JsonResponse
    {
        $raw = Booking::query()->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');

        $out = ['all' => 0, 'pending_payment' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];
        foreach ($raw as $status => $c) {
            // DB values are the spec literals; ignore anything unexpected.
            if (array_key_exists($status, $out)) {
                $out[$status] += (int) $c;
            }
            $out['all'] += (int) $c;
        }

        return response()->json($out);
    }

    public function stats(): JsonResponse
    {
        $revenue    = Booking::query()->revenue();
        $revTotal   = (float) (clone $revenue)->sum('total_amount');
        $count      = (int) (clone $revenue)->count();

        return response()->json([
            'totalRevenue'    => $this->money($revTotal),
            'commission'      => $this->money($this->commissionSum((clone $revenue))),
            'avgBookingValue' => $count > 0 ? $this->money($revTotal / $count) : 0.0,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $b = Booking::query()->with(['unit.owner', 'user', 'payment'])->whereKey($id)->first();

        if (! $b) {
            $this->fail('NOT_FOUND', 'الحجز غير موجود', 404);
        }

        return response()->json(array_merge($this->row($b), [
            'policySnapshot' => $this->policySnapshot($b),
            'timeline'       => $this->timeline($b),
        ]));
    }

    /** @return array<string, mixed> */
    private function row(Booking $b): array
    {
        $total = (float) $b->total_amount;

        // Imputed from the SUBTOTAL, matching Booking::commissionExpr() and the
        // commission total on the stats row above this table. Imputing from
        // gross made a legacy row read 23.00 where the aggregate counted 20.00.
        $commission = $this->commissionOf((float) $b->subtotal, $b->commission_amount);

        // The partner's share is the frozen column, NOT total − commission.
        // `total` is VAT-INCLUSIVE gross, and the VAT is remitted to ZATCA — it
        // was never the partner's. Deriving it from gross overstated every
        // booking by the VAT: 900 gross reported 884.35 where the wallet pays
        // 766.96, so an admin quoting this figure contradicted what the partner
        // was actually paid.
        $partnerShare = $b->partner_share !== null
            ? (float) $b->partner_share
            : round((float) $b->subtotal - $commission, 2);

        return [
            'id'            => (string) $b->id,
            'code'          => $this->code('BKG', $b->id, 4),
            'guestId'       => (string) $b->user_id,
            'guestName'     => $b->user?->name ?? '',
            'guestPhone'    => (string) ($b->user?->phone ?? ''),
            'unitId'        => (string) $b->unit_id,
            'unitName'      => $b->unit?->unit_name ?? '',
            'unitCity'      => $b->unit?->city ?? '',
            'partnerId'     => (string) ($b->unit?->user_id ?? ''),
            'partnerName'   => $b->unit?->owner?->name ?? '',
            'checkIn'       => $this->iso($b->start_date),
            'checkOut'      => $this->iso($b->end_date),
            'nights'        => (int) $b->nights,
            'guests'        => (int) $b->guests,
            'total'         => $this->money($total),
            'commission'    => $commission,
            'partnerShare'  => $this->money($partnerShare),
            'nightlyRate'   => (float) $b->nightly_rate,
            'paymentMethod' => $b->payment?->payment_method ?? '',
            // Refunds are tracked via refunded_amount, not a 'refunded' status.
            'paymentStatus' => (float) ($b->payment?->refunded_amount ?? 0) > 0
                ? 'refunded'
                : $this->paymentStatus($b->payment?->payment_status),
            'moyasarRef'    => $b->payment?->moyasar_id,
            'status'        => (string) $b->status,
            'createdAt'     => $this->iso($b->created_at),
            // Was a hardcoded `false`, so the admin panel's commission-split
            // branch never fired once — including on genuinely Mamsa-owned
            // units, which are exactly the rows it exists for.
            'mamsaOwned'    => (bool) $b->unit?->mamsa_owned,
        ];
    }

    /** Cancellation policy frozen at payment time (§5.8). */
    private function policySnapshot(Booking $b): array
    {
        $snap  = $b->cancellation_snapshot ?? [];
        $tiers = array_map(fn ($t) => [
            'label'         => $t['label'] ?? $t['tier_label'] ?? '',
            'refundPercent' => (int) ($t['refund_percent'] ?? $t['refundPercent'] ?? 0),
        ], $snap['tiers'] ?? []);

        $name = $snap['policy_key'] ?? 'moderate';

        return [
            'name'       => in_array($name, ['flexible', 'moderate', 'strict'], true) ? $name : 'moderate',
            'capturedAt' => $this->iso($b->payment?->paid_at ?? $b->created_at),
            'tiers'      => $tiers,
        ];
    }

    /** Ordered lifecycle events; state ∈ done | current | cancelled. */
    private function timeline(Booking $b): array
    {
        $t   = [];
        $add = function (string $label, mixed $at, string $state) use (&$t) {
            $t[] = ['id' => (string) (count($t) + 1), 'label' => $label, 'at' => $this->iso($at), 'state' => $state];
        };

        $add('إنشاء الحجز', $b->created_at, 'done');

        if ($b->payment?->paid_at) {
            $add('تأكيد الدفع', $b->payment->paid_at, 'done');
        }

        if ($b->status === 'cancelled') {
            $add('إلغاء الحجز', $b->cancelled_at ?? $b->updated_at, 'cancelled');

            return $t;
        }

        $add('تسجيل الوصول', $b->start_date, $b->start_date && $b->start_date->isPast() ? 'done' : 'current');
        $add('تسجيل المغادرة', $b->end_date, $b->end_date && $b->end_date->isPast() ? 'done' : 'current');

        return $t;
    }
}
