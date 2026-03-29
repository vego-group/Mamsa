<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Booking;

class UserBookingsController extends Controller
{
    // قائمة حجوزات المستخدم
    public function index()
    {
        $bookings = Booking::where('user_id', Auth::id())
            ->with('unit')
            ->orderByDesc('created_at')
            ->get();

        return view('user.bookings', compact('bookings'));
    }

    // صفحة تفاصيل الحجز
    public function show(Booking $booking)
    {
        // منع الوصول لحجوزات غيره
        if ($booking->user_id !== Auth::id()) {
            abort(403, 'غير مسموح');
        }

        return view('user.booking-details', compact('booking'));
    }
}