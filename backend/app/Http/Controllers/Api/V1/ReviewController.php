<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_id' => ['required', 'exists:bookings,id'],
            'rating'     => ['required', 'integer', 'min:1', 'max:5'],
            'comment'    => ['nullable', 'string', 'max:1000'],
        ]);

        $booking = Booking::where('id', $data['booking_id'])
            ->where('user_id', auth()->id())
            ->where('status', 'confirmed')
            ->firstOrFail();

        if ($booking->review()->exists()) {
            return response()->json(['message' => 'سبق أن قيّمت هذا الحجز'], 422);
        }

        $review = Review::create([
            'booking_id' => $booking->id,
            'user_id'    => auth()->id(),
            'unit_id'    => $booking->unit_id,
            'rating'     => $data['rating'],
            'comment'    => $data['comment'] ?? null,
        ]);

        return response()->json($review, 201);
    }
}
