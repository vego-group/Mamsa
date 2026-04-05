<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Booking;

class UserBookingsController extends Controller
{
    public function index()
    {
        $bookings = Booking::where('user_id', auth()->id())
            ->with('unit')     // تجيب تفاصيل الوحدة
            ->orderByDesc('created_at')
            ->get();

        return view('user.bookings', compact('bookings'));
    }
}