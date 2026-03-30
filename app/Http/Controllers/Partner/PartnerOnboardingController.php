<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PartnerOnboardingController extends Controller
{
    public function typeForm()
    {
        $user = Auth::user(); /** @var \App\Models\User $user */

        // يجب أن يملك دور Partner
        abort_unless($user->isPartner(), 403);

        // تفاصيل الشريك (الآن من جدول admin_details)
        $profile = $user->adminDetail;

        // إذا سبق واختار النوع → يذهب لصفحة الترخيص
        if ($profile && !empty($profile->type)) {
            return redirect()->route('partner.license.form');
        }

        return view('pages.partner.type');
    }

    public function typeStore(Request $request)
    {
        $user = Auth::user(); /** @var \App\Models\User $user */

        abort_unless($user->isPartner(), 403);

        $data = $request->validate([
            'type' => ['required', 'in:individual,company'],
        ]);

        // إنشاء أو تحديث التفاصيل الإدارية
        $user->adminDetail()->updateOrCreate(
            [], // مهم جداً: لا نستخدم شرط user_id هنا
            [
                'user_id' => $user->id,
                'type' => $data['type'],
                'verification_status' => 'pending',
            ]
        );

        // فردي → ينتقل لإنشاء الوحدة
        if ($data['type'] === 'individual') {
            return redirect()->route('partner.unit.create');
        }

        // شركة → ينتقل لرفع ترخيص السياحة
        return redirect()->route('partner.license.form');
    }

    public function dashboard()
    {
        $user = Auth::user(); /** @var \App\Models\User $user */

        abort_unless($user->isPartner(), 403);

        $profile = $user->adminDetail;

        // لم يكمل نوع الحساب
        if (!$profile || empty($profile->type)) {
            return redirect()->route('partner.type.form');
        }

        // شركة → لديها خطوات إضافية
        if ($profile->type === 'company') {

            // لم يرفع ترخيص السياحة
            if (empty($profile->tourism_permit_no)) {
                return redirect()->route('partner.license.form');
            }

            // الحساب ينتظر مراجعة الادارة
            if ($profile->verification_status !== 'approved') {
                return redirect()->route('partner.review')
                    ->with('status', 'حساب الشركة قيد المراجعة.');
            }
        }

        // عرض لوحة الشريك
        return view('pages.partner.dashboard', compact('profile'));
    }
}