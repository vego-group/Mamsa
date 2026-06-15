<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\MoyasarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private MoyasarService $moyasar) {}

    public function initiate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_id'     => ['required', 'exists:bookings,id'],
            'payment_method' => ['required', 'in:mada,visa,mastercard,apple_pay'],
        ]);

        $booking = Booking::where('id', $data['booking_id'])
            ->where('user_id', auth()->id())
            ->where('status', 'pending')
            ->firstOrFail();

        $payment = Payment::firstOrCreate(
            ['booking_id' => $booking->id],
            [
                'amount'         => $booking->total_amount,
                'payment_method' => $data['payment_method'],
                'payment_status' => 'pending',
            ]
        );

        $description = 'حجز وحدة #' . $booking->id;

        $response = $this->moyasar->initiate(
            (int) ($booking->total_amount * 100),
            $description,
            $data['payment_method'],
            $payment->id
        );

        $payment->update([
            'moyasar_id'       => $response['id'] ?? null,
            'moyasar_response' => $response,
        ]);

        return response()->json($response);
    }

    public function pay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_id'       => ['required', 'exists:payments,id'],
            'token'            => ['nullable', 'string'],
            'apple_pay_token'  => ['nullable', 'string'],
            'save_card'        => ['boolean'],
        ]);

        $payment = Payment::where('id', $data['payment_id'])
            ->whereHas('booking', fn ($q) => $q->where('user_id', auth()->id()))
            ->where('payment_status', 'pending')
            ->firstOrFail();

        if (! empty($data['apple_pay_token'])) {
            $response = $this->moyasar->payWithApplePay(
                $payment->moyasar_id,
                $data['apple_pay_token']
            );
        } else {
            $response = $this->moyasar->pay(
                $payment->moyasar_id,
                $data['token']
            );
        }

        $status = $response['status'] ?? 'failed';

        $payment->update([
            'payment_status'   => $status === 'paid' ? 'paid' : 'failed',
            'paid_at'          => $status === 'paid' ? now() : null,
            'moyasar_response' => $response,
        ]);

        if ($status === 'paid') {
            $payment->booking->update(['status' => 'confirmed']);
        }

        return response()->json($response);
    }

    public function callback(Request $request): JsonResponse
    {
        $payload = $request->all();
        $moyasarId = $payload['id'] ?? null;

        if (! $moyasarId) {
            return response()->json(['ok' => false], 400);
        }

        $payment = Payment::where('moyasar_id', $moyasarId)->first();

        if (! $payment) {
            return response()->json(['ok' => false], 404);
        }

        $status = $payload['status'] ?? 'failed';

        $payment->update([
            'payment_status'   => $status === 'paid' ? 'paid' : 'failed',
            'paid_at'          => $status === 'paid' ? now() : null,
            'moyasar_response' => $payload,
        ]);

        if ($status === 'paid') {
            $payment->booking->update(['status' => 'confirmed']);
        }

        return response()->json(['ok' => true]);
    }

    public function applePayValidateMerchant(Request $request): JsonResponse
    {
        $data = $request->validate([
            'validation_url' => ['required', 'string'],
        ]);

        $session = $this->moyasar->validateApplePayMerchant($data['validation_url']);

        return response()->json($session);
    }

    public function show(Payment $payment): JsonResponse
    {
        if ($payment->booking->user_id !== auth()->id()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        return response()->json($payment->load('booking'));
    }
}
