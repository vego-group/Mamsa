<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PartnerOnboardingController extends Controller
{
    public function typeForm()
    {
        abort_unless(auth()->user()->isPartner(), 403);

        $profile = auth()->user()->partner;

        // إذا اختار النوع خلاص → نوديه يكمل الترخيص
        if ($profile && !empty($profile->type)) {
            return redirect()->route('partner.license.form');
        }

        return view('pages.partner.type');
    }

   public function typeStore(Request $request)
{
    abort_unless(auth()->user()->isPartner(), 403);

    $data = $request->validate([
        'type' => ['required','in:individual,company'],
    ]);

    auth()->user()->partner()->updateOrCreate(
        ['user_id' => auth()->id()],
        [
            'type' => $data['type'],
            'verification_status' => 'pending',
        ]
    );

    // 🔥 التفريق هنا
    if ($data['type'] === 'individual') {
        return redirect()->route('partner.unit.create');
    }

    return redirect()->route('partner.license.form');
}

    public function dashboard()
{
    abort_unless(auth()->user()->isPartner(), 403);

    $profile = auth()->user()->partner;

    if (!$profile || empty($profile->type)) {
        return redirect()->route('partner.type.form');
    }

    // 🔥 فقط الشركة تتقيد بالتصريح
    if ($profile->type === 'company') {

        if (empty($profile->tourism_permit_no)) {
            return redirect()->route('partner.license.form');
        }

        if ($profile->verification_status !== 'approved') {
            return redirect()->route('partner.review')
                ->with('status','حساب الشركة قيد المراجعة.');
        }
    }

    // ✅ الفرد يدخل طبيعي
    return view('pages.partner.dashboard', compact('profile'));
}
}