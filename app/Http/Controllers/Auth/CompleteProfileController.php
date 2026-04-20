<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Mail;

// 🔥 موديل Admin_details (تأكدي موجود)
use App\Models\AdminDetail;

class CompleteProfileController extends Controller
{
    /**
     * عرض صفحة إكمال البيانات
     */
    public function show(Request $request)
    {
        // intent يحدد نوع المستخدم (login / Admin)
        $intent = $request->query('intent','login');

        return view('login.complete-profile', compact('intent'));
    }

    /**
     * حفظ البيانات بعد الإرسال
     */
    public function submit(Request $request)
    {
        $user   = $request->user();
        $intent = $request->input('intent','login');

        /* =====================================
           قواعد التحقق الأساسية
        ===================================== */

        $rules = [
            'name'  => ['required','string','max:255'],
            'email' => ['nullable','email','max:255', Rule::unique('users','email')->ignore($user->id)],
        ];

        // 🔥 إذا Admin الإيميل إجباري
        if ($intent === 'Admin') {
            $rules['email'] = ['required','email','max:255', Rule::unique('users','email')->ignore($user->id)];

            // 🔥 التحقق حسب النوع
            $rules['type'] = ['required', Rule::in(['individual','company'])];

            // فرد
            $rules['national_id'] = ['nullable','required_if:type,individual'];

            // شركة
            $rules['cr_number'] = ['nullable','required_if:type,company'];
        }

        $data = $request->validate($rules);

        /* =====================================
           تجهيز الإيميل
        ===================================== */

        $newEmail = isset($data['email']) ? strtolower(trim($data['email'])) : null;

        // لو تغير الإيميل → نلغي التوثيق
        if ($intent === 'Admin' && $newEmail !== $user->email) {
            $user->email_verified_at = null;
        }

        /* =====================================
           تحديث بيانات المستخدم
        ===================================== */

        $user->name = $data['name'];

        // لا نحط إيميل فاضي
        if (!empty($newEmail)) {
            $user->email = $newEmail;
        }

        $user->save();

        /* =====================================
           🔥 حفظ بيانات الأدمن (Admin_details)
        ===================================== */

        if ($intent === 'Admin') {

            AdminDetail::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'type' => $data['type'],

                    // فرد
                    'national_id' => $data['type'] === 'individual'
                        ? $request->national_id
                        : null,

                    // شركة
                    'cr_number' => $data['type'] === 'company'
                        ? $request->cr_number
                        : null,
                ]
            );
        }

        /* =====================================
        🔥 إرسال OTP للإيميل (للجميع بنفس أسلوبك)
        ===================================== */

        if (!empty($user->email)) {

            // إذا ما هو موثق
            if (is_null($user->email_verified_at)) {

                // توليد الكود
                $code = rand(100000,999999);
                Log::info('تم إنشاء كود تحقق جديد', ['verification_code' => $code]);
                // تخزينه في session
                session([
                    'email_verify_code' => $code,
                    'email_verify_user' => $user->id
                ]);

                try {

                    // إرسال الإيميل
                    Mail::send('emails.otp', [
                        'code' => $code,
                        'user' => $user
                    ], function($msg) use ($user) {
                        $msg->to($user->email)
                            ->subject('رمز التحقق');
                    });

                } catch (\Exception $e) {

                    return back()->withErrors([
                        'email' => 'فشل إرسال الإيميل تأكدي من الإعدادات'
                    ]);
                }

                // 🔥 نفس مسماك القديم (لا تغيرينه)
                return redirect()->route('auth.email.verify.form');
            }
        }


        /* =====================================
        🔥 التوجيه النهائي (نفس منطقك)
        ===================================== */

        if ($user->isAdmin()) {
            return redirect()->route('Admin.dashboard');
        }

        return redirect()->route('home');
    }
}
