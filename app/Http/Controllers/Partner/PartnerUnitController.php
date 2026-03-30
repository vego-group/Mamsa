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

    // 🔥 فقط الشركة تتقيد بالموافقة
    if ($profile->type === 'company' && $profile->verification_status !== 'approved') {
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

    $profile = $user->adminDetail;

    if (!$profile || empty($profile->type)) {
        return redirect()->route('partner.type.form');
    }

    // ✅ التحقق الأساسي
    $rules = [
        'unit_name'   => 'required|string|max:255',
        'unit_type'   => 'required|string|max:255',
        'price'       => 'required|numeric|min:0',
        'capacity'    => 'required|numeric|min:1',
        'city'        => 'required|string|max:255',
        'district'    => 'required|string|max:255',
        'description' => 'nullable|string',
    ];

    // 🔥 إذا فرد لازم هوية + تصريح
    if ($profile->type === 'individual') {
        $rules['national_id'] = 'required|string|max:255';
        $rules['tourism_permit_no'] = 'required|string|max:255';
    }

    $request->validate($rules);

    // ✅ نحفظ الوحدة أول
    $unit = Unit::create([
        'partner_id' => $profile->id,
        'unit_name'  => $request->unit_name,
        'unit_type'  => $request->unit_type,
        'price'      => $request->price,
        'capacity'   => $request->capacity,
        'city'       => $request->city,
        'district'   => $request->district,
        'description'=> $request->description,

        // 🔥 مهم للفرد
        'national_id'        => $request->national_id,
        'tourism_permit_no'  => $request->tourism_permit_no,

        'approval_status' => 'pending_review',
    ]);

    // 🔥 هذا الجزء الجديد فقط (حفظ الصور)
    if ($request->hasFile('images')) {

        foreach ($request->file('images') as $image) {

            $path = $image->store('units', 'public');

            \App\Models\UnitImage::create([
                'unit_id'   => $unit->id,
                'image_url' => $path,
            ]);
        }
    }

    return redirect()->route('partner.dashboard')
        ->with('status','تم إرسال الوحدة وهي الآن قيد المراجعة');
}
    public function licenseForm()
    {
       return view('pages.partner.license');
    }

    public function licenseStore(Request $request)
  {
    $user = auth()->user();

    abort_unless($user->isPartner(), 403);

    $profile = $user->adminDetail;

    if (!$profile) {
        return redirect()->route('partner.type.form');
    }

    $data = $request->validate([
        'company_license_no' => 'required|string|max:255',
        'cr_number'          => 'required|string|max:255',
    ]);

    // تحديث بيانات الشريك
    $profile->update([
        'company_license_no' => $data['company_license_no'],
        'cr_number'          => $data['cr_number'],
        'tourism_permit_no'  => $data['company_license_no'], // مؤقت نفس الرخصة
        'verification_status'=> 'pending',
    ]);

    return redirect()->route('partner.review')
        ->with('status', 'تم إرسال طلب التحقق وهو الآن قيد المراجعة');
   }
   public function review()
{
    return view('pages.partner.review');
}
}