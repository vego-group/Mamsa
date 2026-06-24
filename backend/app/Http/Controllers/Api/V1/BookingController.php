<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Bookings\CancelBookingAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Unit;
use App\Services\CancellationPolicyService;
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

        $booking->load(['unit.images', 'payment', 'review']);

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
    public function cancel(Booking $booking, CancelBookingAction $action): JsonResponse
    {
        if ($booking->user_id !== auth()->id()) {
            return $this->error('غير مصرح', 403);
        }

        $quote = $action->execute($booking, $booking->user);

        return $this->success(
            $quote->toArray(),
            $quote->refundAmount > 0 ? 'تم الإلغاء وسيتم رد المبلغ المستحق' : 'تم إلغاء الحجز',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unit_id'    => ['required', 'exists:units,id'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date'   => ['required', 'date', 'after:start_date'],
            'guests'     => ['required', 'integer', 'min:1'],
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

        $nights = now()->parse($data['start_date'])->diffInDays($data['end_date']);
        $total  = $unit->price * $nights;

        $booking = Booking::create([
            'unit_id'      => $unit->id,
            'user_id'      => auth()->id(),
            'start_date'   => $data['start_date'],
            'end_date'     => $data['end_date'],
            'guests'       => $data['guests'],
            'total_amount' => $total,
            'status'       => 'pending', // explicit so the in-memory model matches the DB default
            'notes'        => $data['notes'] ?? null,
        ]);

        return response()->json(new BookingResource($booking->load('unit.images')), 201);
    }
}
