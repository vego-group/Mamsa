<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\InitiatePaymentRequest;
use App\Http\Requests\Payment\PayPaymentRequest;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\SavedCard;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\BookingConfirmed;
use App\Notifications\NewBooking;
use App\Notifications\PaymentAfterCancellation;
use App\Services\CancellationPolicyService;
use App\Services\MoyasarService;
use App\Support\Booking\Availability;
use App\Support\OpsAlert;
use App\Support\TestMode;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class PaymentController extends Controller
{
    use ApiResponse;

    /** Outcomes of claiming the nights for a payment that just succeeded. */
    private const NIGHTS_HELD = 'held';    // confirmed — the stay is the guest's

    private const PAID_TOO_LATE = 'lost';    // paid, but the nights are gone

    private const NOTHING_TO_DO = 'noop';    // already settled, or not ours to settle

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
            'test_mode' => $this->isTestMode(),
            'currency' => config('moyasar.currency', 'SAR'),
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
            ->where('status', Booking::STATUS_PENDING)
            ->with('unit.images')
            ->firstOrFail();

        $payment = Payment::firstOrCreate(
            ['booking_id' => $booking->id],
            [
                'amount' => $booking->total_amount,
                'payment_method' => $data['payment_method'] ?? 'card',
                'payment_status' => 'pending',
            ],
        );

        $unit = $booking->unit;
        $mainImage = $unit->images->firstWhere('is_main', true) ?? $unit->images->first();

        return $this->success([
            'payment_id' => $payment->id,
            'booking_id' => $booking->id,
            'amount' => (float) $booking->total_amount,
            'amount_halalas' => (int) round($booking->total_amount * 100),
            // Order summary for the payment sidebar — the fee lines are the ones
            // frozen onto the booking at creation, never recomputed here.
            'booking' => [
                'start_date' => $booking->start_date?->toDateString(),
                'end_date' => $booking->end_date?->toDateString(),
                'nights' => $booking->start_date && $booking->end_date
                    ? $booking->start_date->diffInDays($booking->end_date)
                    : null,
                'guests' => $booking->guests,
                'nightly_rate' => (float) $booking->nightly_rate,
                'subtotal' => (float) $booking->subtotal,
                'service_fee' => (float) $booking->service_fee,
                'cleaning_fee' => (float) $booking->cleaning_fee,
                'taxes' => (float) $booking->taxes,
                'unit' => [
                    'name' => $unit->unit_name,
                    'city' => $unit->city,
                    'district' => $unit->district,
                    'image_url' => $mainImage?->url,
                ],
            ],
            'currency' => config('moyasar.currency', 'SAR'),
            'description' => 'حجز وحدة #'.$booking->id.' - '.$booking->unit->unit_name,
            'publishable_key' => $this->moyasar->getPublishableKey(),
            // Browser destination after 3-DS — must be a frontend page, never the
            // API. The page calls POST /payments/verify to confirm server-side.
            'callback_url' => $this->frontendCallbackUrl(),
            // Simulate only when no keys are configured. With pk_test/sk_test the
            // real Moyasar form renders and charges hit Moyasar's test gateway;
            // the frontend shows the test-card hint based on the key prefix.
            'test_mode' => $this->isTestMode(),
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
            'description' => 'حجز وحدة #'.$payment->booking_id,
            // pid lets the frontend callback page verify after the 3-DS redirect.
            'callback_url' => $this->frontendCallbackUrl().'?pid='.$payment->id,
            'metadata' => [
                'payment_id' => $payment->id,
                'booking_id' => $payment->booking_id,
                // Which environment created this payment.
                //
                // Moyasar's webhook registry is account-level and its payment
                // object carries no livemode/mode field, so a staging event and
                // a production event are indistinguishable on arrival. Today
                // they are told apart only by the id being absent from the
                // other database — which stops being true the moment staging is
                // seeded from a production dump. Stamping the environment into
                // metadata, which IS echoed back on the webhook, gives the
                // handler something to check that does not depend on that.
                'env' => (string) config('app.env'),
            ],
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
            $response = $this->moyasar->chargeWithToken($card->moyasar_token, $params);
        } elseif (! empty($data['token'])) {
            $response = $this->moyasar->chargeWithToken($data['token'], $params);
        } else {
            return $this->error('رمز الدفع مطلوب', 422);
        }

        $status = $response['status'] ?? 'failed';

        $payment->update([
            'moyasar_id' => $response['id'] ?? null,
            'moyasar_response' => $response,
            // 'initiated' means 3DS is pending — keep the payment open until callback.
            'payment_status' => match ($status) {
                'paid' => 'paid',
                'initiated' => 'pending',
                default => 'failed',
            },
            'paid_at' => $status === 'paid' ? now() : null,
        ]);

        if ($status === 'paid') {
            $this->confirmBooking($payment->booking);
        }

        return $this->success([
            'status' => $status,
            'payment_id' => $payment->id,
            // For 3DS the frontend must redirect the user to this URL.
            'transaction_url' => $response['source']['transaction_url'] ?? null,
            'message' => $response['source']['message'] ?? null,
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

        $status = $remote['status'] ?? 'failed';
        $amountOk = (int) ($remote['amount'] ?? 0) === (int) round($payment->amount * 100);
        $paid = $status === 'paid' && $amountOk;

        $payment->update([
            'moyasar_id' => $data['moyasar_id'],
            'moyasar_response' => $remote,
            'payment_method' => $remote['source']['type'] ?? $payment->payment_method,
            'payment_status' => $paid ? 'paid' : ($status === 'failed' ? 'failed' : 'pending'),
            'paid_at' => $paid ? now() : null,
        ]);

        if ($paid) {
            $this->confirmBooking($payment->booking);
            // The hosted form only returns a token when the user ticked
            // "save card" — its presence is the user's consent to store it.
            $this->saveCardFromRemote($remote);
        }

        return $this->success([
            'status' => $paid ? 'paid' : $status,
            'payment_id' => $payment->id,
            'booking_id' => $payment->booking_id,
            'message' => $remote['source']['message'] ?? null,
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
            'payment_status' => $verified ? 'paid' : 'failed',
            'paid_at' => $verified ? now() : null,
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
                        'payment_status' => $verified ? 'paid' : 'failed',
                        'paid_at' => $verified ? now() : null,
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
            'id' => $moyasarId,
            'status' => (string) $request->query('status', ''),
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
            'payment_status' => 'paid',
            'paid_at' => now(),
            'moyasar_id' => $response['id'] ?? null,
            'moyasar_response' => $response,
        ]);

        $this->confirmBooking($payment->booking);

        return $this->success([
            'status' => 'paid',
            'payment_id' => $payment->id,
            'test' => $response['test'] ?? false,
        ], 'تم الدفع بنجاح');
    }

    /**
     * Confirm a paid booking and notify whoever OWNS the unit (in-app + email):
     * a partner listing → its partner owner only; a Mamsa-owned listing → all
     * super admins. Single entry point for every payment success path.
     */
    private function confirmBooking(Booking $booking): void
    {
        // Idempotency: a webhook + redirect can both land here. Freeze + notify once.
        if ($booking->status === Booking::STATUS_CONFIRMED) {
            return;
        }

        $outcome = $this->claimNights($booking);

        if ($outcome === self::PAID_TOO_LATE) {
            $this->refundUnrecoverable($booking->refresh());

            return;
        }

        if ($outcome !== self::NIGHTS_HELD) {
            return;
        }

        $booking->refresh()->loadMissing('unit.owner', 'user', 'payment');

        // Wallet ledger (سجل المعاملات): one signed entry per paid booking.
        // Inside the idempotency guard above, so duplicates are impossible.
        try {
            $booking->user?->walletTransactions()->create([
                'ref_code' => 'PAY-'.now()->format('Y').'-'.str_pad((string) $booking->id, 6, '0', STR_PAD_LEFT),
                'type' => WalletTransaction::TYPE_PAYMENT,
                'amount' => -1 * (float) $booking->total_amount,
                'description' => 'دفع حجز — '.($booking->unit?->unit_name ?? 'وحدة #'.$booking->unit_id),
                'status' => 'completed',
                'booking_id' => $booking->id,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e); // ledger is informational — never block a paid booking
        }

        // Best-effort: a mail/SMS failure must never break a paid booking.
        try {
            // Booking notifications go to whoever OWNS the unit:
            //  - Partner listing     → the partner (unit owner) only.
            //  - Mamsa-owned listing → all super admins (no external partner, so
            //    the platform's super admins stand in as the owner).
            $unit = $booking->unit;
            $recipients = $unit?->mamsa_owned
                ? User::role('SuperAdmin')->get()
                : collect(array_filter([$unit?->owner]));

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
     * Take the nights for a paid booking, under the same lock the create takes.
     *
     * The guard this replaced was one line — return early if the booking is
     * already `confirmed` — and it answered the wrong question. `confirmed` is
     * not the only state a payment can arrive into. A booking whose hold
     * expired has been CANCELLED by `bookings:expire-pending`, and cancelling
     * it RELEASED its nights: `Availability` stops counting a cancelled row the
     * moment it is written. So the old code would flip that booking straight
     * back to `confirmed` without asking whether the nights were still there —
     * and if another guest had taken them in between, the platform had sold the
     * same nights twice and told nobody.
     *
     * The window is not theoretical. The expiry job skips bookings whose
     * payment is `paid` or whose Moyasar id was touched in the last 15 minutes,
     * which narrows it a great deal, but a payment older than that and not yet
     * `paid` is cancelled and can still be confirmed by a webhook afterwards —
     * a slow 3-DS challenge, or a gateway retry.
     *
     * Lock order is `units` then `bookings`, matching BookingController exactly.
     * Only ONE unit row is locked here (the apartment is already allocated), so
     * a create holding several siblings and waiting on this one cannot deadlock:
     * this path never waits on a second unit.
     */
    private function claimNights(Booking $booking): string
    {
        return DB::transaction(function () use ($booking) {
            Unit::whereKey($booking->unit_id)->lockForUpdate()->first();

            $fresh = Booking::whereKey($booking->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->status === Booking::STATUS_CONFIRMED) {
                return self::NOTHING_TO_DO;
            }

            // The ordinary path: the booking still holds its own claim on the
            // nights, made under this same lock when it was created. Re-checking
            // availability here would find ITSELF and refuse.
            if ($fresh->status === Booking::STATUS_PENDING) {
                $this->markConfirmed($fresh, restore: false);

                return self::NIGHTS_HELD;
            }

            if ($fresh->status !== Booking::STATUS_CANCELLED) {
                return self::NOTHING_TO_DO;
            }

            // Only a cancellation the PLATFORM made for non-payment may be
            // undone. A guest or partner who cancelled on purpose has decided
            // something, and a late webhook is not permission to reverse it —
            // that money gets refunded instead.
            if ($fresh->cancelled_by !== 'system') {
                return self::PAID_TOO_LATE;
            }

            if (! $this->nightsStillFree($fresh)) {
                return self::PAID_TOO_LATE;
            }

            $this->markConfirmed($fresh, restore: true);

            return self::NIGHTS_HELD;
        });
    }

    /**
     * Are this cancelled booking's dates still free — ignoring itself?
     *
     * `whereKeyNot` is belt-and-braces: a cancelled row is already outside
     * Availability::BLOCKING_STATUSES, so it cannot count itself today. It is
     * there so that widening those statuses later cannot silently turn this
     * check into "the nights are taken — by me".
     *
     * Blocked dates are checked too, for the same reason the create checks
     * them: a partner may have closed the unit while the payment was in flight.
     */
    private function nightsStillFree(Booking $booking): bool
    {
        $start = $booking->start_date instanceof \DateTimeInterface
            ? $booking->start_date->format('Y-m-d') : (string) $booking->start_date;
        $end = $booking->end_date instanceof \DateTimeInterface
            ? $booking->end_date->format('Y-m-d') : (string) $booking->end_date;

        $taken = Availability::conflictingBookings((int) $booking->unit_id, $start, $end)
            ->whereKeyNot($booking->id)
            ->exists();

        if ($taken) {
            return false;
        }

        return ! $booking->loadMissing('unit')->unit
            ?->blockedDates()->overlapping($start, $end)->exists();
    }

    /** Confirm, freezing the refund terms; clear the cancellation when undoing one. */
    private function markConfirmed(Booking $booking, bool $restore): void
    {
        $booking->loadMissing('unit.cancellationPolicy.tiers');

        // FR-036: freeze the cancellation policy onto the booking at payment
        // time so later partner edits never alter this booking's refund terms.
        $booking->update([
            'status' => Booking::STATUS_CONFIRMED,
            'cancellation_snapshot' => $this->cancellationPolicy->snapshotForBooking($booking),
        ] + ($restore ? [
            // A booking that is confirmed and still carries a cancellation
            // reason reads as cancelled on every screen that shows one.
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancellation_reason' => null,
        ] : []));
    }

    /**
     * The guest paid for nights the platform can no longer give them.
     *
     * Refund in full and tell a human. Automatic money back is the correct
     * first move, but it is not the whole remedy: this guest was told their
     * booking was cancelled and then watched a charge appear, so somebody has
     * to contact them. That is what the alert is for.
     *
     * Idempotent on `idempotency_key`, because the webhook and the browser
     * redirect both reach this and a double refund is worse than none.
     */
    private function refundUnrecoverable(Booking $booking): void
    {
        $key = 'late-payment:'.$booking->id;

        if (Refund::where('idempotency_key', $key)->exists()) {
            return;
        }

        $booking->loadMissing('payment', 'user', 'unit');

        $payment = $booking->payment;
        $amount = (float) $booking->total_amount;
        $gateway = null;
        $outcome = 'simulated';

        if ($payment?->moyasar_id) {
            try {
                $gateway = $this->moyasar->refund($payment->moyasar_id, (int) round($amount * 100));
                $outcome = 'gateway';
            } catch (\Throwable $e) {
                // Do NOT rethrow: the booking stays cancelled either way, and
                // losing the alert would leave a charged guest with nobody
                // knowing. The failed row plus the alert IS the handling.
                $outcome = 'failed';

                Log::critical('Late payment: automatic refund failed at the gateway — REFUND BY HAND', [
                    'booking_id' => $booking->id,
                    'payment_id' => $payment->id,
                    'amount' => $amount,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $refund = $booking->refunds()->create([
                'payment_id' => $payment?->id,
                'type' => Refund::TYPE_REFUND,
                'amount' => $amount,
                'refund_percent' => 100,
                'tier_label' => 'دفعة بعد انتهاء المهلة',
                // `reason` is an enum of complaint|host_cancellation|other, so
                // this is `other` with the detail in `tier_label` rather than a
                // new member — an unknown enum value is silently truncated by
                // MySQL and takes the whole write down with it.
                'reason' => Refund::REASON_OTHER,
                'status' => match ($outcome) {
                    'gateway' => Refund::STATUS_PENDING,   // webhook settles it
                    'failed' => Refund::STATUS_FAILED,
                    default => Refund::STATUS_SUCCEEDED, // nothing to call
                },
                'failure_reason' => $outcome === 'failed' ? 'gateway refund threw' : null,
                'idempotency_key' => $key,
                'moyasar_refund_id' => $gateway['id'] ?? null,
                'moyasar_response' => $gateway ?? ['simulated' => true],
            ]);

            if ($outcome !== 'failed' && $payment) {
                $payment->increment('refunded_amount', $amount);
            }

            if ($outcome !== 'failed') {
                $booking->user?->walletTransactions()->create([
                    'ref_code' => 'REF-'.now()->format('Y').'-'.str_pad((string) $refund->id, 6, '0', STR_PAD_LEFT),
                    'type' => WalletTransaction::TYPE_REFUND,
                    'amount' => $amount,
                    'description' => 'استرداد كامل — وصلت الدفعة بعد إلغاء الحجز',
                    'status' => 'completed',
                    'booking_id' => $booking->id,
                    'occurred_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        OpsAlert::raise(new PaymentAfterCancellation($booking, $amount, $outcome));
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
                'visa' => 'visa',
                'master' => 'mastercard',
                'mada' => 'mada',
                default => null,
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
     * Test mode simulates a successful charge instead of calling Moyasar.
     *
     * Two disjoint triggers:
     *  1. No secret key configured AND not production — the dev/staging default,
     *     so the flow works end-to-end without live credentials.
     *  2. An allowlisted test account while TEST_PAYMENTS_MODE is on — this is the
     *     only path allowed in production, and it is scoped to the specific demo
     *     phones (never a real customer, whose live charge always runs).
     */
    private function isTestMode(): bool
    {
        if (blank(config('moyasar.secret_key')) && ! app()->isProduction()) {
            return true;
        }

        return TestMode::paymentBypass(auth()->user()?->phone);
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
