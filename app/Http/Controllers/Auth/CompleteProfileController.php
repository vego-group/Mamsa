<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Mail;

class CompleteProfileController extends Controller
{
    public function show(Request $request)
    {
        $intent = $request->query('intent','login');
        return view('pages.Auth.complete-profile', compact('intent'));
    }

    public function submit(Request $request)
    {
        $user   = $request->user();
        $intent = $request->input('intent','login');

        $rules = [
            'name'  => ['required','string','max:255'],
            'email' => ['nullable','email','max:255', Rule::unique('users','email')->ignore($user->id)],
        ];

        // ✅ الشريك لازم ايميل
        if ($intent === 'partner') {
            $rules['email'] = ['required','email','max:255', Rule::unique('users','email')->ignore($user->id)];
        }

        $data = $request->validate($rules);

        $newEmail = isset($data['email']) ? strtolower(trim($data['email'])) : null;

        // لو تغير الايميل -> نفصل التوثيق
        if ($intent === 'partner' && $newEmail !== $user->email) {
            $user->email_verified_at = null;
        }

        $user->name = $data['name'];

        // ✅ لا نحط ايميل فاضي
        if ($intent === 'partner' && $newEmail) {
            $user->email = $newEmail;
        } elseif (!empty($newEmail)) {
            $user->email = $newEmail;
        }

        $user->save();

        /* =====================================
           إرسال OTP للإيميل (الشريك فقط)
        ===================================== */

        if ($intent === 'partner') {

            // 🔥 أهم شرط عشان ما يعلق
            if (!$user->email) {
                return back()->withErrors(['email' => 'الإيميل مطلوب']);
            }

            if (is_null($user->email_verified_at)) {

                // توليد الكود
                $code = rand(100000,999999);

                session([
                    'email_verify_code' => $code,
                    'email_verify_user' => $user->id
                ]);

                try {

                    Mail::send('emails.otp', [
                        'code' => $code,
                        'user' => $user
                    ], function($msg) use ($user) {
                        $msg->to($user->email)
                            ->subject('رمز التحقق');
                    });

                } catch (\Exception $e) {

                    // لو الإيميل فشل لا يطيح الموقع
                    return back()->withErrors([
                        'email' => 'فشل إرسال الإيميل تأكدي من الإعدادات'
                    ]);
                }

                return redirect()->route('auth.email.verify.form');
            }

            return redirect()->route('admin.dashboard');
        }

        return redirect()->route('home');
    }
}