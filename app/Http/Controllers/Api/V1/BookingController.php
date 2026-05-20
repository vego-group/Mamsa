<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    use ApiResponse;

    public function show(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->user_id !== $request->user()->id) {
            return $this->error('غير مصرح', 403);
        }

        $booking->load(['unit.images', 'unit.features']);

        return $this->success(new BookingResource($booking));
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->user_id !== $request->user()->id) {
            return $this->error('غير مصرح', 403);
        }

        if (! in_array($booking->status, ['pending', 'confirmed'])) {
            return $this->error('لا يمكن إلغاء هذا الحجز', 422);
        }

        $booking->update(['status' => 'cancelled']);

        return $this->success(null, 'تم إلغاء الحجز');
    }
}
