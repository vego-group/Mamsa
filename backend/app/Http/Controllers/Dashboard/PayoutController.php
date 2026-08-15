<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\Booking;
use App\Models\Payout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner payouts — wallet contract §3 and §4.
 */
class PayoutController extends DashboardController
{
    /** GET /payouts?limit= → bare array, newest paidAt first. */
    public function index(Request $request): JsonResponse
    {
        $limit = (int) ($request->query('limit') ?? 20);

        if ($limit < 1 || $limit > 100) {
            $this->fail('VALIDATION', 'قيمة limit غير صالحة', 422, ['limit' => 'يجب أن تكون بين 1 و 100']);
        }

        $payouts = Payout::where('partner_user_id', $request->user()->id)
            ->orderByDesc('paid_at')->limit($limit)->get();

        return $this->ok($payouts->map(fn (Payout $p) => $this->row($p))->values());
    }

    /** GET /payouts/{id} → the transfer plus the bookings it was made of. */
    public function show(Request $request, string $id): JsonResponse
    {
        // Another partner's payout is a 404, not a 403: a 403 would confirm the
        // id exists (contract §4).
        $payout = Payout::where('partner_user_id', $request->user()->id)
            ->whereKey($this->unprefix($id))->first();

        if (! $payout) {
            $this->fail('PAYOUT_NOT_FOUND', 'التحويل غير موجود', 404);
        }

        $bookings = Booking::where('payout_id', $payout->id)->with('unit')->get();

        return $this->ok(array_merge($this->row($payout), [
            'bookings' => $bookings->map(fn (Booking $b) => [
                'bookingId'    => 'b_'.$b->id,
                'bookingCode'  => $b->code ?: (string) $b->id,
                'unitName'     => $b->unit?->unit_name ?? '',
                'checkOut'     => $b->end_date?->toIso8601ZuluString(),
                'gross'        => round((float) $b->total_amount, 2),
                'netBase'      => round((float) $b->subtotal, 2),
                'commission'   => round((float) $b->commission_amount, 2),
                'partnerShare' => round((float) $b->partner_share, 2),
            ])->values(),
        ]));
    }

    /** @return array<string, mixed> */
    private function row(Payout $p): array
    {
        return [
            'id'             => 'po_'.$p->id,
            'reference'      => $p->reference,
            'periodMonth'    => $p->period_month,
            'amount'         => round($p->amount, 2),
            'bookingsCount'  => (int) $p->bookings_count,
            'currency'       => $p->currency,
            'ibanMasked'     => $p->iban_masked,
            'bankName'       => $p->bank_name,
            'status'         => $p->status,
            'paidAt'         => $p->paid_at?->toIso8601ZuluString(),
            'bankReference'  => $p->bank_reference,
            'note'           => $p->note,
            'reversedAt'     => $p->reversed_at?->toIso8601ZuluString(),
            'reversalReason' => $p->reversal_reason,
        ];
    }

    /** Ids go out prefixed (`po_7`); accept them back either way. */
    private function unprefix(string $id): string
    {
        return str_starts_with($id, 'po_') ? substr($id, 3) : $id;
    }
}
