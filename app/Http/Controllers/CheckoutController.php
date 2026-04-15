<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Unit;
use App\Models\Booking;

class CheckoutController extends Controller
{
    public function index(Request $request)
    {
        $unit = Unit::findOrFail($request->unit);

        $checkin = $request->checkin;
        $checkout = $request->checkout;

        $nights = 1;
        $total = $unit->price;

        if($checkin && $checkout){
            $start = strtotime($checkin);
            $end   = strtotime($checkout);

            if($end > $start){
                $nights = ($end - $start) / (60 * 60 * 24);
                $total = $nights * $unit->price;
            }
        }

        return view('checkout', compact(
            'unit',
            'checkin',
            'checkout',
            'nights',
            'total'
        ));
    }
}