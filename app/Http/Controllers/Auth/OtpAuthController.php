<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OtpAuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

    // صفحة إدخال رقم الجوال
    public function showPhoneForm(Request $request)
    {
        $intent = $request->query('intent', 'login');
        return view('pages.Auth.phone', compact('intent'));
    }

    // طلب الكود
    public function requestCode(Request $request)
    {
        $request->validate([
            'phone' => ['required','string','min:8','max:20']
        ]);

        $intent = $request->input('intent', 'login');

        // طلب OTP
        $this->otp->request($request->phone, 'login', $request->ip());

        return redirect()->route('auth.otp.confirm', [
            'intent' => $intent,
            'phone'  => $request->phone
        ]);
    }

    // صفحة إدخال الكود
    public function showConfirmForm(Request $request)
    {
        $intent = $request->query('intent', 'login');
        $phone  = $request->query('phone');

        abort_if(!$phone, 404);

        return view('pages.Auth.confirm', compact('intent', 'phone'));
    }

    // التحقق من الكود
    public function verifyCode(Request $request)
    {
        $validated = $request->validate([
            'phone'  => ['required','string','min:8','max:20'],
            'code'   => ['required','digits_between:4,8'],
            'intent' => ['nullable','in:login,partner']
        ]);

        // تحقق من الكود
        if (!$this->otp->verify($validated['phone'], $validated['code'], 'login')) {
            return back()->withErrors(['code' => 'رمز غير صحيح أو منتهي']);
        }

        // الحصول على المستخدم أو إنشاؤه
        $user = User::where('phone', $validated['phone'])->first();

        if (!$user) {
            $user = User::create([
                'phone' => $validated['phone'],
                'name'  => null,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | إدارة الصلاحيات Roles
        |--------------------------------------------------------------------------
        */

        // 1) إذا المستخدم "أدمن" → لا تغيّر صلاحياته أبداً
        if ($user->isAdmin()) {
            Auth::login($user);
            return redirect()->route('admin.dashboard');
        }

        // 2) إذا intent = partner → اجعله "Partner" دائماً
        if ($validated['intent'] === 'partner') {

            // إذا ليس شريك → اجعله شريك
            if (!$user->isPartner()) {
                $user->assignRole([
                    'name' => 'Partner',
                    'guard_name' => 'web'
                ], true);
            }

            Auth::login($user);
            return redirect()->route('partner.dashboard');
        }

        // 3) مستخدم عادي → يعطيه role User فقط لو أول مرة
        if (!$user->roles()->exists()) {
            $user->assignRole([
                'name' => 'User',
                'guard_name' => 'web'
            ], true);
        }

        // تسجيل الدخول
        Auth::login($user);

        // إذا ما كمل معلوماته
        if (blank($user->name)) {
            return redirect()->route('auth.complete-profile', [
                'intent' => $validated['intent'] ?? 'login'
            ]);
        }

        // المستخدم العادي → يرجع للصفحة الرئيسية
        return redirect()->route('home');
    }
}