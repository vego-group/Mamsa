<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\ComplaintRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Moyasar refund webhook (contract §6.2). Verified by the shared secret_token
 * Moyasar echoes back. This lives on the dashboard path per the contract; the
 * payment-capture webhook stays on /api/v1/payments/callback.
 *
 * Since the complaints work (spec B3) this endpoint is the ONLY place a
 * complaint refund debits a partner. The admin endpoint stops at a `pending`
 * row: a 200 from Moyasar's refund call is acceptance, not settlement, and
 * debiting a partner for money that can still fail is not correctable in an
 * append-only ledger.
 *
 * ── Why the flip is locked ──
 * Moyasar's webhook registry is account-level, and `payment_refunded` is
 * subscribed by BOTH production registrations. The same event therefore reaches
 * more than one handler, and a retry re-delivers it again. So "settle the
 * pending rows" cannot mean "read them, then update them": two deliveries would
 * both read the same pending set and both post a ledger debit. The rows are
 * selected FOR UPDATE and flipped inside one transaction, and only the ids this
 * call actually flipped are settled. A second delivery blocks, then finds
 * nothing pending, and settles nothing.
 */
class WebhookController extends Controller
{
    public function __construct(private readonly ComplaintRefundService $complaints) {}

    public function moyasar(Request $request): JsonResponse
    {
        // Fail CLOSED. This endpoint settles refunds and debits partner
        // wallets, so an unauthenticated caller could fabricate them. A blank
        // secret must reject everything rather than wave it through — a missing
        // env var is a misconfiguration, not consent.
        $secret = (string) config('moyasar.webhook_secret');

        if ($secret === '') {
            Log::error('Moyasar webhook rejected: MOYASAR_WEBHOOK_SECRET is not configured.');

            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'forbidden']], 403);
        }

        if (! hash_equals($secret, (string) $request->input('secret_token'))) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'forbidden']], 403);
        }

        $type      = (string) $request->input('type');
        $data      = (array) $request->input('data', []);
        $moyasarId = $data['id'] ?? $request->input('id');

        // We only act on refund events here; payment.paid is handled elsewhere.
        if (! str_contains($type, 'refund') || ! $moyasarId) {
            return response()->json(['ok' => true]);
        }

        // Environment guard. The payment object carries no mode indicator, but
        // `metadata` is ours and is echoed back, so payments created after this
        // shipped say which environment made them. Mismatch = an event from the
        // other side of a shared Moyasar account: acknowledge and ignore.
        //
        // Absent metadata is NOT treated as a mismatch. Every payment taken
        // before this stamp existed has none, and refusing those would strand
        // real refunds on live bookings. The guard therefore hardens over time
        // rather than switching on — which is why the operational rule about
        // never seeding staging with production moyasar_ids still stands.
        $eventEnv = $data['metadata']['env'] ?? null;

        if ($eventEnv !== null && $eventEnv !== (string) config('app.env')) {
            Log::warning('Moyasar webhook ignored: event belongs to another environment', [
                'event_env' => $eventEnv,
                'app_env'   => config('app.env'),
                'moyasar_id' => $moyasarId,
            ]);

            return response()->json(['ok' => true]);
        }

        $payment = Payment::where('moyasar_id', $moyasarId)->first();

        // Unknown payment. This is the normal answer for an event belonging to
        // the OTHER environment: the account is shared across test and live
        // keys, so staging's events are delivered here too and simply do not
        // match anything. Verified in production's own delivery log — several
        // recent callbacks 404'd on ids absent from this database.
        if (! $payment) {
            return response()->json(['ok' => true]);
        }

        // A payment created after the stamp went live must carry one. Before
        // that date none exists, and refusing those would strand refunds on
        // live bookings — but leaving "absent" acceptable forever would let the
        // guard decay to nothing as old payments age out.
        if ($eventEnv === null && $this->stampWasLiveFor($payment)) {
            Log::error('Moyasar webhook ignored: payment postdates the env stamp but carries none', [
                'payment_id' => $payment->id,
                'moyasar_id' => $moyasarId,
            ]);

            return response()->json(['ok' => true]);
        }

        // Which refunds this event covers is decided from the payment's
        // cumulative `refunded` total, because Moyasar has no refund object and
        // the event carries no per-refund id. Taking every pending row instead
        // would settle a second, unrelated refund on the same payment — a
        // partner debited for money that never left.
        $refundedHalalas = $this->gatewayRefundedHalalas($data, $payment);

        if ($refundedHalalas === null) {
            Log::error('Moyasar refund webhook: no refunded total available, settling nothing', [
                'payment_id' => $payment->id,
            ]);

            return response()->json(['ok' => true]);
        }

        $settled = $this->complaints->settlePending($payment, $refundedHalalas);

        if ($settled->isEmpty()) {
            // A replay, the same event through the second registration, or a
            // total that could not be attributed — the service has alerted in
            // that last case. None of them is an error to Moyasar.
            return response()->json(['ok' => true]);
        }

        Log::info('Moyasar refund webhook settled', [
            'payment_id' => $payment->id,
            'type'       => $type,
            'refund_ids' => $settled->pluck('id')->all(),
        ]);

        foreach ($settled as $refund) {
            if ($refund->complaint_id) {
                // Ledger debit + close the complaint + notify both sides.
                $this->complaints->settle($refund);

                continue;
            }

            // Cancellation refunds keep their original behaviour: the guest is
            // told, and no ledger entry is written because the partner was
            // never credited for a booking that did not complete.
            $this->notifyCancellationRefund($refund);
        }

        return response()->json(['ok' => true]);
    }

    /** Whether this payment was created after the env stamp went live. */
    private function stampWasLiveFor(Payment $payment): bool
    {
        $from = config('complaints.env_stamp_live_from');

        if (blank($from) || ! $payment->created_at) {
            return false;
        }

        return $payment->created_at->greaterThanOrEqualTo(\Illuminate\Support\Carbon::parse($from));
    }

    /**
     * The payment's cumulative refunded total, in halalas.
     *
     * Preferred from the event body, which carries the payment object. If the
     * field is absent the payment is re-read from the API rather than assumed —
     * and if that fails too, the caller settles nothing. There is no safe
     * default here: any guess is a ledger entry.
     */
    private function gatewayRefundedHalalas(array $data, Payment $payment): ?int
    {
        if (isset($data['refunded']) && is_numeric($data['refunded'])) {
            return (int) $data['refunded'];
        }

        try {
            $fresh = app(\App\Services\MoyasarService::class)->fetchPayment((string) $payment->moyasar_id);

            return isset($fresh['refunded']) ? (int) $fresh['refunded'] : null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function notifyCancellationRefund(Refund $refund): void
    {
        try {
            $booking = $refund->booking?->loadMissing('user', 'unit');
            $amount  = round((float) $refund->amount, 2);

            if ($booking && $amount > 0) {
                $booking->user?->notify(new \App\Notifications\RefundProcessed($booking, $amount));
            }
        } catch (\Throwable $e) {
            report($e); // a mail failure must never 500 a webhook
        }
    }
}
