<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\InitiatePaymentRequest;
use App\Http\Requests\Payment\PayPaymentRequest;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\SavedCard;
use App\Models\User;
use App\Notifications\BookingConfirmed;
use App\Notifications\NewBooking;
use App\Services\CancellationPolicyService;
use App\Services\MoyasarService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class PaymentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly MoyasarService $moyasar,
        private readonly CancellationPolicyService $cancellationPolicy,
    ) {}

    /**
     * Gateway config for pages that tokenise cards outside checkout (e.g. the
     * wallet's "add card" form). Same flags initiate() returns, minus a booking.
     */
    public function config(): JsonResponse
    {
        return $this->success([
            'publishable_key' => $this->moyasar->getPublishableKey(),
            'test_mode'       => $this->isTestMode(),
            'currency'        => config('moyasar.currency', 'SAR'),
        ]);
    }

    /**
     * Step 1 — create (or fetch) the pending payment for a booking and hand the
     * frontend everything it needs to render the Moyasar form.
     */
    public function initiate(InitiatePaymentRequest $request): JsonResponse
    {
        $this->assertGatewayConfigured();

        $data = $request->validated();

        $booking = Booking::where('id', $data['booking_id'])
            ->where('user_id', auth()->id())
            ->where('status', 'pending')
            ->with('unit.images')
            ->firstOrFail();

        $payment = Payment::firstOrCreate(
            ['booking_id' => $booking->id],
            [
                'amount'         => $booking->total_amount,
                'payment_method' => $data['payment_method'] ?? 'card',
                'payment_status' => 'pending',
            ],
        );

        $unit      = $booking->unit;
        $mainImage = $unit->images->firstWhere('is_main', true) ?? $unit->images->first();

        return $this->success([
            'payment_id'      => $payment->id,
            'booking_id'      => $booking->id,
            'amount'          => (float) $booking->total_amount,
            'amount_halalas'  => (int) round($booking->total_amount * 100),
            // Order summary for the payment sidebar — the fee lines are the ones
            // frozen onto the booking at creation, never recomputed here.
            'booking'         => [
                'start_date'   => $booking->start_date?->toDateString(),
                'end_date'     => $booking->end_date?->toDateString(),
                'nights'       => $booking->start_date && $booking->end_date
                    ? $booking->start_date->diffInDays($booking->end_date)
                    : null,
                'guests'       => $booking->guests,
                'nightly_rate' => (float) $booking->nightly_rate,
                'subtotal'     => (float) $booking->subtotal,
                'service_fee'  => (float) $booking->service_fee,
                'cleaning_fee' => (float) $booking->cleaning_fee,
                'taxes'        => (float) $booking->taxes,
                'unit'         => [
                    'name'      => $unit->unit_name,
                    'city'      => $unit->city,
                    'district'  => $unit->district,
                    'image_url' => $mainImage?->url,
                ],
            ],
            'currency'        => config('moyasar.currency', 'SAR'),
            'description'     => 'حجز وحدة #'.$booking->id.' - '.$booking->unit->unit_name,
            'publishable_key' => $this->moyasar->getPublishableKey(),
            // Browser destination after 3-DS — must be a frontend page, never the
            // API. The page calls POST /payments/verify to confirm server-side.
            'callback_url'    => $this->frontendCallbackUrl(),
            // Simulate only when no keys are configured. With pk_test/sk_test the
            // real Moyasar form renders and charges hit Moyasar's test gateway;
            // the frontend shows the test-card hint based on the key prefix.
            'test_mode'       => $this->isTestMode(),
        ]);
    }

    /**
     * Step 2 — charge. Real mode uses a Moyasar.js card token (or Apple Pay token);
     * test mode (no secret key configured) simulates a successful charge so the
     * end-to-end flow works without live credentials.
     */
    public function pay(PayPaymentRequest $request): JsonResponse
    {
        $this->assertGatewayConfigured();

        $data = $request->validated();

        $payment = Payment::where('id', $data['payment_id'])
            ->whereHas('booking', fn ($q) => $q->where('user_id', auth()->id()))
            ->where('payment_status', 'pending')
            ->with('booking')
            ->firstOrFail();

        // ── Test mode ──────────────────────────────────────────────
        if ($this->isTestMode()) {
            return $this->markPaid($payment, ['id' => 'test_'.uniqid(), 'status' => 'paid', 'test' => true]);
        }

        // ── Live Moyasar charge ────────────────────────────────────
        $params = [
            'amount_halalas' => (int) round($payment->amount * 100),
            'description'    => 'حجز وحدة #'.$payment->booking_id,
            // pid lets the frontend callback page verify after the 3-DS redirect.
            'callback_url'   => $this->frontendCallbackUrl().'?pid='.$payment->id,
            'metadata'       => ['payment_id' => $payment->id, 'booking_id' => $payment->booking_id],
        ];

        if (! empty($data['apple_pay_token'])) {
            $response = $this->moyasar->chargeWithApplePay($data['apple_pay_token'], $params);
        } elseif (! empty($data['saved_card_id'])) {
            // Quick pay — the token belongs to the caller or the charge is refused.
            $card = SavedCard::where('id', $data['saved_card_id'])
                ->where('user_id', auth()->id())
                ->whereNotNull('moyasar_token')
                ->first();

            if (! $card) {
                return $this->error('البطاقة المحفوظة غير صالحة للدفع', 422);
            }

            $params['cvc'] = $data['cvc'] ?? null;
            $response      = $this->moyasar->chargeWithToken($card->moyasar_token, $params);
        } elseif (! empty($data['token'])) {
            $response = $this->moyasar->chargeWithToken($data['token'], $params);
        } else {
            return $this->error('رمز الدفع مطلوب', 422);
        }

        $status = $response['status'] ?? 'failed';

        $payment->update([
            'moyasar_id'       => $response['id'] ?? null,
            'moyasar_response' => $response,
            // 'initiated' means 3DS is pending — keep the payment open until callback.
            'payment_status'   => match ($status) {
                'paid'      => 'paid',
                'initiated' => 'pending',
                default     => 'failed',
            },
            'paid_at'          => $status === 'paid' ? now() : null,
        ]);

        if ($status === 'paid') {
            $this->confirmBooking($payment->booking);
        }

        return $this->success([
            'status'          => $status,
            'payment_id'      => $payment->id,
            // For 3DS the frontend must redirect the user to this URL.
            'transaction_url' => $response['source']['transaction_url'] ?? null,
            'message'         => $response['source']['message'] ?? null,
        ], $status === 'paid' ? 'تم الدفع بنجاح' : 'تتطلب العملية إجراءً إضافياً');
    }

    /**
     * Verify a payment completed via the Moyasar hosted form. The form charges
     * Moyasar directly (card never touches our server) and returns a Moyasar
     * payment id; we re-fetch it server-side, validate amount + status, and
     * confirm the booking only when genuinely paid.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_id' => ['required', 'integer', 'exists:payments,id'],
            'moyasar_id' => ['required', 'string'],
        ]);

        $payment = Payment::where('id', $data['payment_id'])
            ->whereHas('booking', fn ($q) => $q->where('user_id', auth()->id()))
            ->with('booking')
            ->firstOrFail();

        $remote = $this->moyasar->fetchPayment($data['moyasar_id']);

        $status   = $remote['status'] ?? 'failed';
        $amountOk = (int) ($remote['amount'] ?? 0) === (int) round($payment->amount * 100);
        $paid     = $status === 'paid' && $amountOk;

        $payment->update([
            'moyasar_id'       => $data['moyasar_id'],
            'moyasar_response' => $remote,
            'payment_method'   => $remote['source']['type'] ?? $payment->payment_method,
            'payment_status'   => $paid ? 'paid' : ($status === 'failed' ? 'failed' : 'pending'),
            'paid_at'          => $paid ? now() : null,
        ]);

        if ($paid) {
            $this->confirmBooking($payment->booking);
            // The hosted form only returns a token when the user ticked
            // "save card" — its presence is the user's consent to store it.
            $this->saveCardFromRemote($remote);
        }

        return $this->success([
            'status'     => $paid ? 'paid' : $status,
            'payment_id' => $payment->id,
            'booking_id' => $payment->booking_id,
            'message'    => $remote['source']['message'] ?? null,
        ], $paid ? 'تم الدفع بنجاح' : 'لم يكتمل الدفع');
    }

    /**
     * Moyasar redirect/webhook callback — re-verifies status server-side.
     */
    public function callback(Request $request): JsonResponse
    {
        // Verify the webhook secret token when configured (Moyasar includes the
        // token you set on the webhook). Reject forged calls.
        $webhookSecret = (string) config('moyasar.webhook_secret');
        if ($webhookSecret !== '' && ! hash_equals($webhookSecret, (string) $request->input('secret_token'))) {
            return $this->error('توقيع غير صالح', 401);
        }

        // Webhook payload nests the payment under `data`; redirect uses top-level `id`.
        $moyasarId = $request->input('data.id', $request->input('id'));

        if (! $moyasarId) {
            return $this->error('معرف الدفع مفقود', 400);
        }

        $payment = Payment::where('moyasar_id', $moyasarId)->with('booking')->first();

        if (! $payment) {
            return $this->error('الدفع غير موجود', 404);
        }

        // Idempotency: a redirect + webhook (or duplicate webhooks) can both land
        // here. Once paid, never re-evaluate — a later spurious call must not
        // flip a settled payment back to failed.
        if ($payment->payment_status === 'paid') {
            return $this->success(['ok' => true, 'status' => 'paid']);
        }

        $verified = $this->moyasar->verifyCallback($moyasarId, (float) $payment->amount);

        $payment->update([
            'payment_status'   => $verified ? 'paid' : 'failed',
            'paid_at'          => $verified ? now() : null,
            'moyasar_response' => $request->all(),
        ]);

        if ($verified) {
            $this->confirmBooking($payment->booking);
        }

        return $this->success(['ok' => true, 'status' => $verified ? 'paid' : 'failed']);
    }

    /**
     * Browser return leg after 3-DS (GET). Safety net for payments created with
     * an API callback_url: confirm server-side best-effort, then always 302 the
     * user onto the frontend callback page — never show raw JSON to a human.
     */
    public function callbackRedirect(Request $request): RedirectResponse
    {
        $moyasarId = (string) $request->query('id', '');

        try {
            if ($moyasarId !== '') {
                $payment = Payment::where('moyasar_id', $moyasarId)->with('booking')->first();

                // Same idempotency rule as callback(): a settled payment is final.
                if ($payment && $payment->payment_status !== 'paid') {
                    $verified = $this->moyasar->verifyCallback($moyasarId, (float) $payment->amount);

                    $payment->update([
                        'payment_status'   => $verified ? 'paid' : 'failed',
                        'paid_at'          => $verified ? now() : null,
                        'moyasar_response' => $request->query(),
                    ]);

                    if ($verified) {
                        $this->confirmBooking($payment->booking);
                    }
                }
            }
        } catch (\Throwable $e) {
            // The user's card may already be charged — verification failures must
            // never strand them here; the webhook + frontend verify will settle it.
            report($e);
        }

        return redirect()->away($this->frontendCallbackUrl().'?'.http_build_query([
            'id'      => $moyasarId,
            'status'  => (string) $request->query('status', ''),
            'message' => (string) $request->query('message', ''),
        ]));
    }

    public function applePayValidateMerchant(Request $request): JsonResponse
    {
        $data = $request->validate([
            'validation_url' => ['required', 'string'],
        ]);

        return $this->success($this->moyasar->validateApplePayMerchant($data['validation_url']));
    }

    public function show(Payment $payment): JsonResponse
    {
        if ($payment->booking->user_id !== auth()->id()) {
            return $this->error('غير مصرح', 403);
        }

        return $this->success($payment->load('booking.unit'));
    }

    private function markPaid(Payment $payment, array $response): JsonResponse
    {
        $payment->update([
            'payment_status'   => 'paid',
            'paid_at'          => now(),
            'moyasar_id'       => $response['id'] ?? null,
            'moyasar_response' => $response,
        ]);

        $this->confirmBooking($payment->booking);

        return $this->success([
            'status'     => 'paid',
            'payment_id' => $payment->id,
            'test'       => $response['test'] ?? false,
        ], 'تم الدفع بنجاح');
    }

    /**
     * Confirm a paid booking and notify the unit's partner + all admins
     * (in-app + email). Single entry point for every payment success path.
     */
    private function confirmBooking(Booking $booking): void
    {
        // Idempotency: a webhook + redirect can both land here. Freeze + notify once.
        if ($booking->status === Booking::STATUS_CONFIRMED) {
            return;
        }

        $booking->loadMissing('unit.cancellationPolicy.tiers');

        // FR-036: freeze the cancellation policy onto the booking at payment time
        // so later partner edits never alter this booking's refund terms.
        $booking->update([
            'status'                => Booking::STATUS_CONFIRMED,
            'cancellation_snapshot' => $this->cancellationPolicy->snapshotForBooking($booking),
        ]);

        $booking->loadMissing('unit.owner', 'user', 'payment');

        // Wallet ledger (سجل المعاملات): one signed entry per paid booking.
        // Inside the idempotency guard above, so duplicates are impossible.
        try {
            $booking->user?->walletTransactions()->create([
                'ref_code'    => 'PAY-'.now()->format('Y').'-'.str_pad((string) $booking->id, 6, '0', STR_PAD_LEFT),
                'type'        => \App\Models\WalletTransaction::TYPE_PAYMENT,
                'amount'      => -1 * (float) $booking->total_amount,
                'description' => 'دفع حجز — '.($booking->unit?->unit_name ?? 'وحدة #'.$booking->unit_id),
                'status'      => 'completed',
                'booking_id'  => $booking->id,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e); // ledger is informational — never block a paid booking
        }

        // Best-effort: a mail/SMS failure must never break a paid booking.
        try {
            $recipients = User::role(['Admin', 'SuperAdmin'])->get();
            if ($owner = $booking->unit?->owner) {
                $recipients = $recipients->push($owner)->unique('id');
            }

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new NewBooking($booking));
            }

            // FR-034 / FR-100: SMS booking confirmation to the guest.
            $booking->user?->notify(new BookingConfirmed($booking));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Persist a reusable card token returned by a paid Moyasar payment
     * (hosted form with the "save card" box ticked). Best-effort: a card-save
     * failure must never affect the payment result.
     */
    private function saveCardFromRemote(array $remote): void
    {
        try {
            $token = $remote['source']['token'] ?? null;
            if (! $token) {
                return;
            }

            // Moyasar reports the scheme as `company`; map to our enum and
            // skip anything we don't support rather than fail.
            $brand = match ($remote['source']['company'] ?? '') {
                'visa'   => 'visa',
                'master' => 'mastercard',
                'mada'   => 'mada',
                default  => null,
            };

            // Masked PAN looks like "XXXX-XXXX-XXXX-1234" — keep the last 4.
            $last4 = substr(preg_replace('/\D/', '', (string) ($remote['source']['number'] ?? '')), -4);

            if (! $brand || strlen($last4) !== 4) {
                return;
            }

            $user = auth()->user();

            // One row per physical card: re-saving the same card refreshes its token.
            $card = SavedCard::updateOrCreate(
                ['user_id' => $user->id, 'brand' => $brand, 'last4' => $last4],
                ['moyasar_token' => $token],
            );

            if (! $user->savedCards()->where('is_default', true)->exists()) {
                $card->update(['is_default' => true]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Frontend page that receives Moyasar's post-3DS query params (id/status/message). */
    private function frontendCallbackUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/payment/callback';
    }

    /**
     * Test mode simulates a successful charge so the flow works without live
     * credentials. It is ONLY ever allowed outside production — a missing secret
     * key in production is a misconfiguration, never a licence to fake payments.
     */
    private function isTestMode(): bool
    {
        return blank(config('moyasar.secret_key')) && ! app()->isProduction();
    }

    /**
     * Fail fast if the gateway is not configured in production. Prevents both
     * silent test-mode fakes and confusing downstream 401s from Moyasar.
     */
    private function assertGatewayConfigured(): void
    {
        if (app()->isProduction()
            && (blank(config('moyasar.secret_key')) || blank(config('moyasar.publishable_key')))) {
            abort(503, 'بوابة الدفع غير مهيأة. يرجى المحاولة لاحقاً.');
        }
    }
}
