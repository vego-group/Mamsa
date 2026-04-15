<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class VerifyEmailController extends Controller
{
    public function show()
    {
        return view('login.email-verify');
    }

    public function submit(Request $request)
    {
        $request->validate([
            'code' => 'required'
        ]);

        $user = $request->user();

        $sessionCode = session('email_verify_code');
        $sessionUser = session('email_verify_user');

        // 🔥 التصحيح هنا (كان فيه error)
        if (!$sessionCode || $sessionCode != $request->code || $sessionUser != $user->id) {
            return back()->withErrors([
                'code' => 'رمز التحقق غير صحيح'
            ]);
        }

        $user->email_verified_at = now();
        $user->save();

        session()->forget(['email_verify_code','email_verify_user']);

        if ($user->isAdmin()) {
            return redirect()->route('Admin.dashboard');
        }

        return redirect()->route('home');
    }

    public function resend(Request $request)
    {
        $user = $request->user();

        $code = rand(100000,999999);

        session([
            'email_verify_code' => $code,
            'email_verify_user' => $user->id
        ]);

        Mail::send('emails.otp', [
            'code' => $code,
            'user' => $user
        ], function($msg) use ($user) {
            $msg->to($user->email)
                ->subject('رمز التحقق');
        });

        return back()->with('status', 'تم إعادة إرسال الكود');
    }
}