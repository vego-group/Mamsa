<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\User;
use App\Services\OtpService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Partner-dashboard auth (contract §1): OTP only, httpOnly session cookie.
 * The OTP itself is never returned — staging uses OTP_FIXED_CODE instead.
 */
class AuthController extends DashboardController
{
    public function __construct(private readonly OtpService $otp) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'phone' => ['required', 'regex:/^5\d{8}$/'],
        ], ['phone.regex' => 'رقم الجوال يجب أن يكون 9 أرقام ويبدأ بـ 5']);

        // OtpService enforces the 60s resend cooldown + daily caps and sends
        // the SMS; the route throttle adds 3-per-10-min per phone.
        $this->otp->request($data['phone'], 'login', $request->ip());

        return $this->ok();
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'phone' => ['required', 'regex:/^5\d{8}$/'],
            'code'  => ['required', 'digits:6'],
        ]);

        // Prove phone ownership FIRST — the account gate below must not leak
        // whether a phone belongs to a partner to callers without the OTP.
        // Throws OtpException (OTP_WRONG / OTP_EXPIRED / OTP_LOCKED).
        $this->otp->verify($data['phone'], $data['code'], 'login');

        $user = User::where('phone', PhoneNumber::toE164Ksa($data['phone']))
            ->with('partnerDetail')
            ->first();

        if (! $user || ! $user->isPartner()) {
            $this->fail('PARTNER_NOT_FOUND', 'هذا الرقم غير مسجّل كشريك', 404);
        }

        if (! $user->is_active) {
            $this->fail('ACCOUNT_SUSPENDED', 'تم إيقاف حسابك، تواصل مع الدعم', 403);
        }

        // pending AND rejected both read as "under review" to the dashboard —
        // a rejected applicant re-submits via the registration flow.
        if ($user->partnerDetail?->status !== \App\Models\PartnerDetail::STATUS_APPROVED) {
            $this->fail('ACCOUNT_PENDING', 'طلب انضمامك قيد المراجعة', 403);
        }

        Auth::guard('dashboard')->login($user);
        $request->session()->regenerate();

        return $this->ok();
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('dashboard')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->ok();
    }
}
