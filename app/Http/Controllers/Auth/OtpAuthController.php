<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OtpAuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

    public function showPhoneForm(Request $request)
    {
        $intent = $request->query('intent', 'login');
        return view('login.phone', compact('intent'));
    }

    public function requestCode(Request $request)
    {
        $request->validate([
            'phone' => ['required','string','min:8','max:20']
        ]);

        $intent = $request->input('intent','login');

        $this->otp->request($request->phone, 'login', $request->ip());

        return redirect()->route('auth.otp.confirm', [
            'intent' => $intent,
            'phone'  => $request->phone
        ]);
    }

    public function resend(Request $request)
    {
        $request->validate([
            'phone' => ['required','string']
        ]);

        $this->otp->request($request->phone, 'login', $request->ip());

        return back()->with('status', 'تم إعادة إرسال الرمز');
    }

    public function showConfirmForm(Request $request)
    {
        $intent = $request->query('intent','login');
        $phone  = $request->query('phone');

        abort_if(!$phone, 404);

        return view('login.confirm', compact('intent','phone'));
    }

    public function verifyCode(Request $request)
    {
        $validated = $request->validate([
            'phone'  => ['required','string','min:8','max:20'],
            'code'   => ['required','digits_between:4,8'],
            'intent' => ['nullable','in:login,Admin']
        ]);

        if (!$this->otp->verify($validated['phone'], $validated['code'], 'login')) {
            return back()->withErrors(['code' => 'رمز غير صحيح أو منتهي']);
        }

        $user = User::where('phone', $validated['phone'])->first();

        if (!$user) {
            $user = User::create([
                'phone' => $validated['phone'],
                'name'  => null,
                'is_active' => 1,
            ]);
        }

        // 🔥 دمج النظامين (roles + pivot)
        if (!$user->roles()->exists()) {

            if ($validated['intent'] === 'Admin') {
                $user->assignRole('Admin', true);
            } else {
                $user->assignRole('User', true);
            }

            // fallback لو roles ما اشتغل
            if (!DB::table('user_roles')->where('user_id', $user->id)->exists()) {

                $roleName = ($validated['intent'] === 'Admin') ? 'Admin' : 'user';

                $role = Role::where('name', $roleName)->first();

                if ($role) {
                    DB::table('user_roles')->insert([
                        'user_id' => $user->id,
                        'role_id' => $role->id,
                    ]);
                }
            }
        }

        Auth::login($user);

        if (blank($user->name)) {
            return redirect()->route('auth.complete-profile', [
                'intent' => $validated['intent'] ?? 'login'
            ]);
        }

        if ($user->isAdmin()) {
            return redirect()->route('Admin.dashboard');
        }

        return redirect()->route('home');
    }
}