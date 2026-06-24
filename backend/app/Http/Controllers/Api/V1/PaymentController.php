<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\InitiatePaymentRequest;
use App\Http\Requests\Payment\PayPaymentRequest;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\BookingConfirmed;
use App\Notifications\NewBooking;
use App\Services\CancellationPolicyService;
use App\Services\MoyasarService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
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
     * Step 1 — create (or fetch) the pending payment for a booking and hand the
     * frontend everything it needs to render the Moyasar form.
     */
    public function initiate(InitiatePaymentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $booking = Booking::where('id', $data['booking_id'])
            ->where('user_id', auth()->id())
            ->where('status', 'pending')
            ->with('unit')
            ->firstOrFail();

        $payment = Payment::firstOrCreate(
            ['booking_id' => $booking->id],
            [
                'amount'         => $booking->total_amount,
                'payment_method' => $data['payment_method'] ?? 'card',
                'payment_status' => 'pending',
            ],
        );

        return $this->success([
            'payment_id'      => $payment->id,
            'booking_id'      => $booking->id,
            'amount'          => (float) $booking->total_amount,
            'amount_halalas'  => (int) round($booking->total_amount * 100),
            'currency'        => config('moyasar.currency', 'SAR'),
            'description'     => 'حجز وحدة #'.$booking->id.' - '.$booking->unit->unit_name,
            'publishable_key' => $this->moyasar->getPublishableKey(),
            'callback_url'    => url('/api/v1/payments/callback'),
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
            'callback_url'   => url('/api/v1/payments/callback'),
            'metadata'       => ['payment_id' => $payment->id, 'booking_id' => $payment->booking_id],
        ];

        if (! empty($data['apple_pay_token'])) {
            $response = $this->moyasar->chargeWithApplePay($data['apple_pay_token'], $params);
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

        $booking->loadMissing('unit.owner', 'user');

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

    private function isTestMode(): bool
    {
        return blank(config('moyasar.secret_key'));
    }
}
