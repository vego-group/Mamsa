<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    public function show()
    {
        return view('pages.email.email-verify');
    }

    public function submit(Request $request)
    {
        $request->validate([
            'code' => 'required'
        ]);

        $user = $request->user();

        $sessionCode = session('email_verify_code');
        $sessionUser = session('email_verify_user');

        // 🔥 تحقق الكود
        if (!$sessionCode || $sessionCode != $request->code || $sessionUser != $user->id) {
            return back()->withErrors([
                'code' => 'رمز التحقق غير صحيح'
            ]);
        }

        // ✅ توثيق الإيميل
        $user->email_verified_at = now();
        $user->save();

        // حذف الكود
        session()->forget(['email_verify_code','email_verify_user']);

        // 🔥 تحويل حسب الدور
        if ($user->isAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        return redirect()->route('home');
    }
}