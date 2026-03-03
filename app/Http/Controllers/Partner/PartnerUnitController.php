<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Unit;

class PartnerUnitController extends Controller
{
    /* ===============================
        Create Unit Page
    ================================ */
    public function create()
    {
        abort_unless(auth()->user()->isPartner(), 403);

        $profile = auth()->user()->partner;

        if (!$profile || empty($profile->type)) {
            return redirect()->route('partner.type.form');
        }

        if ($profile->type === 'company' && $profile->status !== 'verified') {
            return redirect()->route('partner.review')
                ->with('status','حساب الشركة قيد المراجعة.');
        }

        return view('pages.partner.unit');
    }

    /* ===============================
        Store Unit
    ================================ */
    public function store(Request $request)
    {
        $user = auth()->user();

        abort_unless($user->isPartner(), 403);

        $profile = $user->partner;

        if (!$profile || empty($profile->type)) {
            return redirect()->route('partner.type.form');
        }

        $request->validate([
            'unit_name'   => 'required|string|max:255',
            'unit_type'   => 'required|string|max:255',
            'price'       => 'required|numeric|min:0',
            'capacity'    => 'required|numeric|min:1',
            'city'        => 'required|string|max:255',
            'district'    => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        Unit::create([
            'partner_id' => $profile->id,
            'unit_name'  => $request->unit_name,
            'unit_type'  => $request->unit_type,
            'price'      => $request->price,
            'capacity'   => $request->capacity,
            'city'       => $request->city,
            'district'   => $request->district,
            'description'=> $request->description,
            'approval_status' => 'pending_review',
        ]);

        return redirect()->route('partner.dashboard')
            ->with('status','تم حفظ الوحدة بنجاح.');
    }
}