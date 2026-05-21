<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Unit;
use App\Services\MoyasarService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private MoyasarService $moyasar) {}

    /**
     * Initiate payment: create pending booking + payment, return Moyasar publishable key + metadata.
     * The frontend uses the publishable key to tokenize the card via Moyasar.js, then POSTs the token
     * back to Moyasar directly. On success, Moyasar calls our callback_url.
     */
    public function initiate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unit_id'   => ['required', 'exists:units,id'],
            'checkin'   => ['required', 'date', 'after_or_equal:today'],
            'checkout'  => ['required', 'date', 'after:checkin'],
            'notes'     => ['nullable', 'string', 'max:1000'],
        ]);

        $unit = Unit::findOrFail($validated['unit_id']);

        if ($unit->approval_status !== 'approved' || $unit->status !== 'available') {
            return $this->error('الوحدة غير متاحة للحجز', 422);
        }

        $checkin  = Carbon::parse($validated['checkin']);
        $checkout = Carbon::parse($validated['checkout']);
        $nights   = $checkin->diffInDays($checkout);

        if ($nights < 1) {
            return $this->error('يجب أن تكون مدة الإقامة ليلة واحدة على الأقل', 422);
        }

        // Check for conflicting confirmed/pending bookings
        $conflict = $unit->bookings()
            ->whereIn('status', ['pending', 'confirmed'])
            ->where(function ($q) use ($validated) {
                $q->whereBetween('start_date', [$validated['checkin'], $validated['checkout']])
                  ->orWhereBetween('end_date', [$validated['checkin'], $validated['checkout']])
                  ->orWhere(function ($q) use ($validated) {
                      $q->where('start_date', '<=', $validated['checkin'])
                        ->where('end_date', '>=', $validated['checkout']);
                  });
            })->exists();

        if ($conflict) {
            return $this->error('الوحدة محجوزة في هذه الفترة', 409);
        }

        $totalAmount = round($unit->price * $nights, 2);

        return DB::transaction(function () use ($request, $unit, $validated, $nights, $totalAmount, $checkin, $checkout) {
            $booking = Booking::create([
                'unit_id'      => $unit->id,
                'user_id'      => $request->user()->id,
                'start_date'   => $validated['checkin'],
                'end_date'     => $validated['checkout'],
                'total_amount' => $totalAmount,
                'status'       => 'pending',
                'notes'        => $validated['notes'] ?? null,
            ]);

            $payment = Payment::create([
                'booking_id'     => $booking->id,
                'amount'         => $totalAmount,
                'payment_method' => 'creditcard',
                'payment_status' => 'pending',
            ]);

            $callbackUrl = url("/api/v1/payments/callback?payment_id={$payment->id}");

            return $this->success([
                'payment_id'      => $payment->id,
                'booking_id'      => $booking->id,
                'amount'          => $totalAmount,
                'amount_halalas'  => (int) round($totalAmount * 100),
                'currency'        => 'SAR',
                'nights'          => $nights,
                'unit'            => [
                    'id'   => $unit->id,
                    'name' => $unit->unit_name,
                ],
                'publishable_key' => $this->moyasar->getPublishableKey(),
                'callback_url'    => $callbackUrl,
                'description'     => "حجز وحدة {$unit->unit_name} من {$checkin->toDateString()} إلى {$checkout->toDateString()}",
            ], 'تم إنشاء طلب الدفع');
        });
    }

    /**
     * Pay: user submits card details → we charge via Moyasar API directly.
     *
     * Response scenarios:
     *  - paid       → booking confirmed immediately
     *  - initiated  → 3DS required, return transaction_url for user to open in browser/webview
     *  - failed     → booking cancelled, return error message
     */
    public function pay(Request $request, Payment $payment): JsonResponse
    {
        if ($payment->booking->user_id !== $request->user()->id) {
            return $this->error('غير مصرح', 403);
        }

        if ($payment->payment_status !== 'pending') {
            return $this->error('هذه العملية تمت معالجتها مسبقاً', 422);
        }

        // Token only — raw card numbers must NEVER be sent to our server (PCI DSS compliance)
        // The frontend uses Moyasar.js / Moyasar SDK with the publishable_key to tokenize
        // the card directly with Moyasar, then sends only the resulting token here.
        $validated = $request->validate([
            'token' => ['required', 'string', 'starts_with:token_'],
        ]);

        $booking     = $payment->booking;
        $unit        = $booking->unit;
        $callbackUrl = url("/api/v1/payments/callback?payment_id={$payment->id}");

        try {
            $result = $this->moyasar->chargeWithToken($validated['token'], [
                'amount_halalas' => (int) round($payment->amount * 100),
                'description'    => "حجز وحدة {$unit->unit_name} — #{$booking->id}",
                'callback_url'   => $callbackUrl,
                'metadata'       => ['payment_id' => $payment->id, 'booking_id' => $booking->id],
            ]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 503);
        }

        $moyasarStatus = $result['status'] ?? 'failed';
        $moyasarId     = $result['id'] ?? null;
        $source        = $result['source'] ?? [];

        // ── 3DS required ──────────────────────────────────────────────────────
        if ($moyasarStatus === 'initiated') {
            // Save the Moyasar ID so callback can find the payment
            $payment->update([
                'moyasar_id'       => $moyasarId,
                'moyasar_response' => $result,
            ]);

            return $this->success([
                'requires_3ds'    => true,
                'transaction_url' => $source['transaction_url'] ?? null,
                'payment_id'      => $payment->id,
                'moyasar_id'      => $moyasarId,
            ], 'مطلوب التحقق الثنائي — افتح رابط 3DS');
        }

        // ── Paid immediately ──────────────────────────────────────────────────
        if ($moyasarStatus === 'paid') {
            DB::transaction(function () use ($payment, $result, $moyasarId, $source) {
                $payment->update([
                    'payment_status'    => 'paid',
                    'paid_at'           => now(),
                    'moyasar_id'        => $moyasarId,
                    'moyasar_reference' => $source['reference_number'] ?? null,
                    'moyasar_response'  => $result,
                ]);
                $payment->booking->update(['status' => 'confirmed']);
            });

            return $this->success([
                'requires_3ds' => false,
                'payment_id'   => $payment->id,
                'booking_id'   => $payment->booking_id,
                'moyasar_id'   => $moyasarId,
            ], 'تم الدفع والحجز بنجاح');
        }

        // ── Failed ────────────────────────────────────────────────────────────
        $errorMessage = $source['message'] ?? ($result['message'] ?? 'فشل الدفع');

        $payment->update([
            'payment_status'   => 'failed',
            'moyasar_id'       => $moyasarId,
            'moyasar_response' => $result,
        ]);
        $payment->booking->update(['status' => 'cancelled']);

        return $this->error($errorMessage, 422, [
            'moyasar_status' => $moyasarStatus,
        ]);
    }

    /**
     * Moyasar webhook callback — called by Moyasar server after payment completes.
     * Verifies the secret token header, then re-fetches payment from Moyasar to confirm.
     */
    public function callback(Request $request): JsonResponse
    {
        // Verify Moyasar webhook secret token
        $webhookSecret = config('moyasar.webhook_secret');
        if ($webhookSecret && $request->header('Authorization') !== $webhookSecret) {
            return $this->error('Unauthorized', 401);
        }

        $validated = $request->validate([
            'id'         => ['required', 'string'],    // Moyasar payment ID
            'status'     => ['required', 'string'],
            'payment_id' => ['required', 'exists:payments,id'],
        ]);

        $payment = Payment::with('booking')->findOrFail($validated['payment_id']);

        if ($payment->payment_status === 'paid') {
            return $this->success(['booking_id' => $payment->booking_id], 'تم الدفع مسبقاً');
        }

        $verified = $this->moyasar->verifyCallback($validated['id'], (float) $payment->amount);

        if (! $verified) {
            $payment->update(['payment_status' => 'failed']);
            $payment->booking->update(['status' => 'cancelled']);

            return $this->error('فشل التحقق من الدفع', 422);
        }

        DB::transaction(function () use ($payment, $validated) {
            $moyasarData = $this->moyasar->fetchPayment($validated['id']);

            $payment->update([
                'payment_status'   => 'paid',
                'payment_method'   => 'creditcard',
                'paid_at'          => now(),
                'moyasar_id'       => $validated['id'],
                'moyasar_reference'=> $moyasarData['source']['reference_number'] ?? null,
                'moyasar_response' => $moyasarData,
            ]);

            $payment->booking->update(['status' => 'confirmed']);
        });

        return $this->success([
            'booking_id'  => $payment->booking_id,
            'moyasar_id'  => $validated['id'],
        ], 'تم الدفع والحجز بنجاح');
    }

    public function show(Payment $payment): JsonResponse
    {
        if ($payment->booking->user_id !== request()->user()->id) {
            return $this->error('غير مصرح', 403);
        }

        return $this->success([
            'id'             => $payment->id,
            'booking_id'     => $payment->booking_id,
            'amount'         => (float) $payment->amount,
            'status'         => $payment->payment_status,
            'payment_method' => $payment->payment_method,
            'paid_at'        => $payment->paid_at?->toIso8601String(),
            'moyasar_id'     => $payment->moyasar_id,
        ]);
    }
}
