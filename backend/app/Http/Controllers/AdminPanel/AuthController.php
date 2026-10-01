<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Http\Resources\AdminPanel\AdminProfileResource;
use App\Models\User;
use App\Services\OtpService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin-panel auth (BACKEND_SPEC §3): OTP only, httpOnly session cookie.
 * No passwords. The OTP is never returned; non-production uses OTP_FIXED_CODE.
 *
 * The guard is `admin-panel` (session) — separate from the partner `dashboard`
 * guard so both can be logged in simultaneously in one browser.
 */
class AuthController extends Controller
{
    /** Body phone form the frontend sends: +9665XXXXXXXX (also tolerates 5XXXXXXXX). */
    private const PHONE_RULE = 'regex:/^(\+?9665\d{8}|05\d{8}|5\d{8})$/';

    public function __construct(private readonly OtpService $otp) {}

    /** POST /admin/auth/request-otp — only registered admins may request. */
    public function requestOtp(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'phone' => ['required', 'string', self::PHONE_RULE],
        ], ['phone.regex' => 'رقم جوال غير صالح']);

        // Gate on admin membership at request time (spec §3): non-admins get 403,
        // and no SMS is sent for them.
        if (! $this->adminByPhone($data['phone'])) {
            $this->fail('FORBIDDEN', 'هذا الرقم غير مصرّح له بالدخول', 403);
        }

        // OtpService enforces the 60s resend cooldown + daily caps and sends the
        // SMS; the route throttle adds 3-per-10-min per phone.
        $this->otp->request($data['phone'], 'admin-login', $request->ip());

        return $this->ok();
    }

    /** POST /admin/auth/verify-otp — verify code, open the session, return the profile. */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'phone' => ['required', 'string', self::PHONE_RULE],
            'code'  => ['required', 'digits:6'],
        ], [
            'phone.required' => 'رقم الجوال مطلوب',
            'phone.regex'    => 'رقم جوال غير صالح',
            'code.required'  => 'رمز التحقق مطلوب',
            'code.digits'    => 'رمز التحقق يجب أن يكون 6 أرقام',
        ]);

        // Prove phone ownership FIRST (throws OtpException on failure), then gate.
        $this->otp->verify($data['phone'], $data['code'], 'admin-login');

        $user = $this->adminByPhone($data['phone']);

        if (! $user) {
            $this->fail('FORBIDDEN', 'هذا الرقم غير مصرّح له بالدخول', 403);
        }

        if (! $user->is_active) {
            $this->fail('FORBIDDEN', 'تم إيقاف هذا الحساب', 403);
        }

        Auth::guard('admin-panel')->login($user, true);
        $request->session()->regenerate();

        return response()->json([
            'ok'    => true,
            'admin' => (new AdminProfileResource($user))->resolve(),
        ]);
    }

    /** GET /admin/me — restore the session on app load (or 401). */
    public function me(Request $request): AdminProfileResource
    {
        return new AdminProfileResource($request->user());
    }

    /** POST /admin/auth/logout — clear the cookie. */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('admin-panel')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->ok();
    }

    /** Resolve an ACTIVE-role admin User by any accepted phone form, or null. */
    private function adminByPhone(string $rawPhone): ?User
    {
        $user = User::where('phone', PhoneNumber::toE164Ksa($rawPhone))->first();

        return $user && $user->isAdmin() ? $user : null;
    }
}
