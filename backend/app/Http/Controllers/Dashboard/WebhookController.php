<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Moyasar refund webhook (contract §6.2). Verified by the shared secret_token
 * Moyasar echoes back. Flips a processing refund to completed. This lives on
 * the dashboard path per the contract; the payment-capture webhook stays on
 * /api/v1/payments/callback.
 */
class WebhookController extends Controller
{
    public function moyasar(Request $request): JsonResponse
    {
        // Fail CLOSED. This endpoint flips refunds to settled and triggers the
        // settlement email, so an unauthenticated caller could fabricate refund
        // events. A blank secret must therefore reject everything rather than
        // wave it through — a missing env var is a misconfiguration, not consent.
        $secret = (string) config('moyasar.webhook_secret');

        if ($secret === '') {
            Log::error('Moyasar webhook rejected: MOYASAR_WEBHOOK_SECRET is not configured.');

            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'forbidden']], 403);
        }

        if (! hash_equals($secret, (string) $request->input('secret_token'))) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'forbidden']], 403);
        }

        $type = (string) $request->input('type');
        $data = (array) $request->input('data', []);
        $moyasarId = $data['id'] ?? $request->input('id');

        // We only act on refund events here; payment.paid is handled elsewhere.
        if (! str_contains($type, 'refund') || ! $moyasarId) {
            return response()->json(['ok' => true]);
        }

        $payment = Payment::where('moyasar_id', $moyasarId)->first();
        if ($payment) {
            // Snapshot the rows being flipped so the settlement email fires
            // once per refund — a replayed webhook finds nothing processing.
            //
            // 'pending', NOT 'processing'. The refunds enum is
            // pending|succeeded|failed, so a row could never hold 'processing'
            // — this matched nothing, every gateway refund stayed unsettled
            // forever and the settlement email never fired. Same wrong value
            // the write side used; fixing one without the other would have
            // left refunds silently stuck.
            $settledAmount = (float) $payment->refunds()->where('status', 'pending')->sum('amount');

            $flipped = $payment->refunds()
                ->where('status', 'pending')
                ->update(['status' => 'succeeded']);

            Log::info('Moyasar refund webhook settled', ['payment_id' => $payment->id, 'type' => $type]);

            if ($flipped > 0 && $settledAmount > 0) {
                try {
                    $booking = $payment->booking?->loadMissing('user', 'unit');
                    $booking?->user?->notify(new \App\Notifications\RefundProcessed($booking, $settledAmount));
                } catch (\Throwable $e) {
                    report($e); // a mail failure must never 500 a webhook
                }
            }
        }

        return response()->json(['ok' => true]);
    }
}
