<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Bookings\CancelBookingAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Unit;
use App\Services\CancellationPolicyService;
use App\Support\Pricing;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    use ApiResponse;

    public function show(Booking $booking): BookingResource|JsonResponse
    {
        if ($booking->user_id !== auth()->id()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $booking->load(['unit.images', 'unit.features', 'unit.owner', 'payment', 'review']);

        return new BookingResource($booking);
    }

    /**
     * Preview the refund the guest would receive if they cancelled now —
     * FR-043/044. Read-only; never touches the gateway.
     */
    public function cancellationPreview(Booking $booking, CancellationPolicyService $policy): JsonResponse
    {
        if ($booking->user_id !== auth()->id()) {
            return $this->error('غير مصرح', 403);
        }

        return $this->success($policy->quote($booking)->toArray());
    }

    /**
     * Cancel a booking and run the automatic refund/void — FR-046/047.
     * All business rules live in CancelBookingAction; the controller stays thin.
     */
    public function cancel(Request $request, Booking $booking, CancelBookingAction $action): JsonResponse
    {
        if ($booking->user_id !== auth()->id()) {
            return $this->error('غير مصرح', 403);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $quote = $action->execute($booking, $booking->user, $data['reason'] ?? null);

        return $this->success(
            $quote->toArray(),
            $quote->refundAmount > 0 ? 'تم الإلغاء وسيتم رد المبلغ المستحق' : 'تم إلغاء الحجز',
        );
    }

    public function store(Request $request): JsonResponse
    {
        // Email task doc §2 — a verified email is required before booking so
        // confirmations/refund notices have a trusted channel. The frontend
        // branches on the machine code and routes to the /user/email flow.
        if (config('booking.require_verified_email')) {
            $user = $request->user();
            if (blank($user->email) || ! $user->email_verified_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'يجب توثيق بريدك الإلكتروني قبل إتمام الحجز.',
                    'code'    => 'EMAIL_VERIFICATION_REQUIRED',
                ], 422);
            }
        }

        $data = $request->validate([
            'unit_id'    => ['required', 'exists:units,id'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date'   => ['required', 'date', 'after:start_date'],
            'guests'     => ['required', 'integer', 'min:1'],
            // Split counts (§2.3). `guests` stays the TOTAL; children is a
            // subset of it. Optional so older clients sending only `guests`
            // keep working (children defaults to 0).
            'children'   => ['sometimes', 'integer', 'min:0', 'lte:guests'],
            'notes'      => ['nullable', 'string', 'max:500'],
        ]);

        $unit = Unit::where('id', $data['unit_id'])
            ->where('approval_status', 'approved')
            ->where('status', 'available')
            ->firstOrFail();

        // conflict check
        $conflict = Booking::where('unit_id', $unit->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where(function ($q) use ($data) {
                $q->whereBetween('start_date', [$data['start_date'], $data['end_date']])
                  ->orWhereBetween('end_date', [$data['start_date'], $data['end_date']])
                  ->orWhere(function ($inner) use ($data) {
                      $inner->where('start_date', '<=', $data['start_date'])
                            ->where('end_date', '>=', $data['end_date']);
                  });
            })
            ->exists();

        if ($conflict) {
            return response()->json(['message' => 'الوحدة محجوزة في هذه الفترة'], 422);
        }

        // Blocked dates: partner manual closures + external (iCal) bookings.
        $blocked = $unit->blockedDates()
            ->overlapping($data['start_date'], $data['end_date'])
            ->exists();

        if ($blocked) {
            return response()->json(['message' => 'الوحدة غير متاحة في هذه الفترة'], 422);
        }

        $nights  = (int) now()->parse($data['start_date'])->diffInDays($data['end_date']);
        $pricing = Pricing::breakdown((float) $unit->price, $nights);

        $booking = Booking::create([
            'unit_id'           => $unit->id,
            'user_id'           => auth()->id(),
            'start_date'        => $data['start_date'],
            'end_date'          => $data['end_date'],
            'guests'            => $data['guests'],
            'children'          => $data['children'] ?? 0,
            'nightly_rate'      => $pricing['nightly_rate'],
            'subtotal'          => $pricing['subtotal'],
            // Fees abolished 2026-07-18 — stored as explicit 0 (not null) so
            // the columns stay uniform next to the fee-era historical rows.
            'service_fee'         => 0,
            'service_fee_percent' => 0,
            'cleaning_fee'        => 0,
            'taxes'             => $pricing['taxes'],
            'tax_percent'       => $pricing['tax_percent'],
            'commission_rate'   => $pricing['commission_rate'],
            'commission_amount' => $pricing['commission_amount'],
            'total_amount'      => $pricing['total'],
            'status'            => 'pending', // explicit so the in-memory model matches the DB default
            'notes'             => $data['notes'] ?? null,
        ]);

        return response()->json(new BookingResource($booking->load('unit.images')), 201);
    }
}
