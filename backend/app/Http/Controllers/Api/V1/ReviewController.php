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
        // Validation per requirements §6.1: rating 1-5, comment 10-1000 chars.
        $data = $request->validate([
            'booking_id' => ['required', 'exists:bookings,id'],
            'rating'     => ['required', 'integer', 'min:1', 'max:5'],
            'comment'    => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        // FR-050: reviews are only allowed once the stay is completed. The
        // bookings:complete scheduled command transitions Confirmed → Completed
        // after checkout.
        $booking = Booking::where('id', $data['booking_id'])
            ->where('user_id', auth()->id())
            ->where('status', Booking::STATUS_COMPLETED)
            ->firstOrFail();

        if ($booking->review()->exists()) {
            return response()->json(['message' => 'سبق أن قيّمت هذا الحجز'], 422);
        }

        $review = Review::create([
            'booking_id' => $booking->id,
            'user_id'    => auth()->id(),
            'unit_id'    => $booking->unit_id,
            'rating'     => $data['rating'],
            'comment'    => $data['comment'],
        ]);

        $review->load('user:id,name');

        // Shape matches the review object the frontend adapter expects.
        return response()->json([
            'id'         => (string) $review->id,
            'booking_id' => (string) $review->booking_id,
            'unit_id'    => (string) $review->unit_id,
            'user_id'    => (string) $review->user_id,
            'user_name'  => $review->user?->name,
            'rating'     => $review->rating,
            'comment'    => $review->comment,
            'created_at' => $review->created_at,
        ], 201);
    }
}
