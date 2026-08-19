<?php

namespace App\Http\Controllers\Api\V1\Partner;

use App\Actions\Bookings\HostCancelBookingAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $unitIds = $request->user()->units()->pluck('id');

        $bookings = Booking::whereIn('unit_id', $unitIds)
            ->with(['unit.images', 'user', 'payment'])
            ->latest()
            ->paginate(15);

        return response()->json(BookingResource::collection($bookings));
    }

    /**
     * POST /api/v1/partner/bookings/{booking}/cancel — host cancellation.
     *
     * The partner can no longer honour the stay (double-booked elsewhere, a
     * burst pipe). The guest did nothing wrong, so no cancellation policy
     * applies to them: they are refunded 100% of what they paid, the partner
     * forfeits their share and Mamsa forfeits its commission.
     *
     * Shares HostCancelBookingAction with the partner dashboard rather than
     * reimplementing it — two refund paths would eventually disagree about how
     * much a guest gets back, and that is not a bug anyone notices quickly.
     */
    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'reason.required' => 'سبب الإلغاء مطلوب',
            'reason.min'      => 'يرجى كتابة سبب واضح للإلغاء',
        ]);

        // Ownership: the booking must be on one of THIS partner's units. 404
        // rather than 403 — a partner should not be able to probe which booking
        // ids exist on someone else's listing.
        $booking->loadMissing('unit');

        if ((int) $booking->unit?->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'الحجز غير موجود'], 404);
        }

        $updated = app(HostCancelBookingAction::class)->execute(
            $booking,
            $request->user(),
            $data['reason'],
            $request->header('Idempotency-Key'),
        );

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء الحجز واسترداد كامل المبلغ للضيف',
            'data'    => new BookingResource($updated),
        ]);
    }
}
