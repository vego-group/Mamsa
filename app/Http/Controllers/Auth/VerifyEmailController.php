<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Partner;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VerifyEmailController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = User::findOrFail($request->route('id'));

        // التحقق من صحة الرابط
        if (! hash_equals(
            (string) $request->route('hash'),
            sha1($user->getEmailForVerification())
        )) {
            abort(403);
        }

        // تسجيل دخول تلقائي
        Auth::login($user);

        // تفعيل الإيميل
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return $this->redirectAfterVerify($user);
    }

    private function redirectAfterVerify($user)
    {
        // إذا كان Partner
        if ($user->isPartner()) {

            // إنشاء سجل partner إذا غير موجود
            $profile = $user->partner;

            if (!$profile) {
                $profile = Partner::create([
                    'user_id' => $user->id
                ]);
            }

            // إذا لم يختر النوع
            if (empty($profile->type)) {
                return redirect()->route('partner.type.form');
            }

            // إذا اختار النوع
            return redirect()->route('admin.dashboard');
        }

        return redirect()->route('home');
    }
}