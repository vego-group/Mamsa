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
