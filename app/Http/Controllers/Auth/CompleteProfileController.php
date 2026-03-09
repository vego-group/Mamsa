<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        if ($intent === 'partner') {
            $user->email = $newEmail;
        } elseif (!empty($newEmail)) {
            // لو تبين حتى المستخدم العادي يحفظ ايميل (اختياري)
            $user->email = $newEmail;
        }

        $user->save();

        // ✅ فقط الشريك نرسل له Verification (حسب طلبك)
        if ($intent === 'partner') {
            if ($user->email && is_null($user->email_verified_at)) {
                $user->sendEmailVerificationNotification();
                return redirect()->route('verification.notice');
            }

            // بعد التوثيق نوديه لفلو الشريك (اختاري المسار اللي عندك)
            return redirect()->route('partner.dashboard');
        }

        return redirect()->route('home');
    }
}