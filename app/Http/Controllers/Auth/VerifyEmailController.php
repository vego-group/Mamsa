<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VerifyEmailController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = User::findOrFail($request->route('id'));

        // التحقق من صحة التوقيع
        if (! hash_equals(
            (string) $request->route('hash'),
            sha1($user->getEmailForVerification())
        )) {
            abort(403);
        }

        // تسجيل دخول تلقائي
        Auth::login($user);

        // تفعيل الإيميل إذا لم يكن مفعل
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return $this->redirectAfterVerify($user);
    }

    private function redirectAfterVerify($user)
    {
        // إذا كان شريك
        if ($user->isPartner()) {

            $profile = $user->partner;

            // لم يختر النوع بعد
            if (!$profile || empty($profile->type)) {
                return redirect()->route('partner.type.form');
            }

            // اختار النوع
            return redirect()->route('partner.dashboard');
        }

        // مستخدم عادي
        return redirect()->route('home');
    }
}