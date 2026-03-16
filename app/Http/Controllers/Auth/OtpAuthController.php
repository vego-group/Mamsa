<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OtpAuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

    public function showPhoneForm(Request $request)
    {
        $intent = $request->query('intent', 'login');
        return view('pages.Auth.phone', compact('intent'));
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

    public function showConfirmForm(Request $request)
    {
        $intent = $request->query('intent','login');
        $phone  = $request->query('phone');

        abort_if(!$phone, 404);

        return view('pages.Auth.confirm', compact('intent','phone'));
    }

    public function verifyCode(Request $request)
    {
        $validated = $request->validate([
            'phone'  => ['required','string','min:8','max:20'],
            'code'   => ['required','digits_between:4,8'],
            'intent' => ['nullable','in:login,partner']
        ]);

        if (!$this->otp->verify($validated['phone'], $validated['code'], 'login')) {
            return back()->withErrors(['code' => 'رمز غير صحيح أو منتهي']);
        }

        $user = User::firstOrCreate(
            ['phone' => $validated['phone']],
            [
                'name'     => null,
            ]
        );

        /* ==============================
           Assign Role
        ============================== */

        if ($validated['intent'] === 'partner') {

            $partnerRole = Role::where('role_name', 'Partner')->first();

            if ($partnerRole) {
                $user->roles()->syncWithoutDetaching([$partnerRole->id]);
            }

        } else {

            $userRole = Role::where('role_name', 'User')->first();

            if ($userRole) {
                $user->roles()->syncWithoutDetaching([$userRole->id]);
            }
        }

        Auth::login($user);

        if (blank($user->name)) {
            return redirect()->route('auth.complete-profile', [
                'intent' => $validated['intent'] ?? 'login'
            ]);
        }

        if ($validated['intent'] === 'partner') {
            return redirect()->route('partner.dashboard');
        }

        return redirect()->route('home');
    }
}