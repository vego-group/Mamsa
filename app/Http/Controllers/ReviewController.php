<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Booking;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'unit_id' => 'required|exists:units,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string'
        ]);

        // 🔥 تحقق أنه حجز فعلاً
        $hasBooking = Booking::where('user_id', auth()->id())
            ->where('unit_id', $request->unit_id)
            ->where('status', 'confirmed')
            ->whereDate('end_date','<=',now())
            ->exists();

        if(!$hasBooking){
            return back()->withErrors(['error'=>'لا يمكنك التقييم بدون حجز']);
        }

        // 🔥 منع التكرار
        if(Review::where('user_id', auth()->id())
            ->where('unit_id',$request->unit_id)->exists()){
            return back()->withErrors(['error'=>'لقد قمت بالتقييم مسبقًا']);
        }

        Review::create([
            'user_id' => auth()->id(),
            'unit_id' => $request->unit_id,
            'rating' => $request->rating,
            'comment' => $request->comment
        ]);

        return back()->with('success','تم إضافة التقييم ⭐');
    }
}