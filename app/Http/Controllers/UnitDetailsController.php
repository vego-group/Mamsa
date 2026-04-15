<?php

namespace App\Http\Controllers;

use App\Models\Unit;

class UnitDetailsController extends Controller
{
    public function show(Unit $unit)
    {
        // 🔥 تحميل كل العلاقات
        $unit->load('images','user','reviews','features');

        // 🔥 التحقق هل يقدر يقيم
        $canReview = false;

        if(auth()->check()){
            $canReview = \App\Models\Booking::where('user_id', auth()->id())
                ->where('unit_id', $unit->id)
                ->where('status', 'confirmed')
                ->whereDate('end_date', '<=', now())
                ->exists();
        }

        // 🔥 نخلي view موحد
        return view('units.details', compact('unit','canReview'));
    }
}