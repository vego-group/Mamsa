<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function process(Request $request)
    {
        $booking = Booking::findOrFail($request->booking_id);

        return view('payment.redirect', compact('booking'));
    }

    // 🔥 هنا بعد الدفع
    public function success(Request $request)
    {
        $booking = Booking::findOrFail($request->booking_id);

        // تحديث الحجز
        $booking->update([
            'status' => 'confirmed'
        ]);

        // حفظ الدفع
        Payment::create([
            'booking_id' => $booking->id,
            'amount' => $booking->total_amount,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'paid_at' => now()
        ]);

        return redirect('/')->with('success','تم الحجز بنجاح 🎉');
    }
}