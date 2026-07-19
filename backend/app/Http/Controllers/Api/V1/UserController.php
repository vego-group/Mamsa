<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\User;
use App\Services\OtpService;
use App\Support\PhoneNumber;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OtpService $otp) {}

    public function profile(Request $request): JsonResponse
    {
        return response()->json($request->user()->load('partnerDetail'));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'  => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'email', 'max:150', 'unique:users,email,'.$user->id],
        ]);

        // A changed email drops back to unverified — it must re-pass the
        // /user/email OTP flow before it counts as a trusted channel.
        if (array_key_exists('email', $data) && strtolower((string) $data['email']) !== strtolower((string) $user->email)) {
            $data['email_verified_at'] = null;
        }

        $user->forceFill($data)->save();

        return response()->json($request->user()->fresh());
    }

    public function bookings(Request $request): JsonResponse
    {
        $bookings = $request->user()
            ->bookings()
            ->with(['unit.images', 'unit.owner', 'payment', 'review'])
            ->latest()
            ->paginate(10);

        return response()->json(BookingResource::collection($bookings));
    }

    /**
     * §7.2 — step 1: send an OTP to the NEW phone to prove ownership before
     * switching. Rejects a number already taken by another account.
     */
    public function changePhone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'new_phone' => ['required', 'string', 'regex:/^05\d{8}$/'],
        ], ['new_phone.regex' => 'رقم الجوال غير صحيح (يجب أن يبدأ بـ 05).']);

        $this->assertPhoneAvailable($data['new_phone'], $request->user());

        $this->otp->request($data['new_phone'], 'change-phone', $request->ip());

        return $this->success(null, 'تم إرسال رمز التحقق إلى الرقم الجديد');
    }

    /**
     * §7.2 — step 2: verify the OTP sent to the new phone and switch it over.
     */
    public function verifyChangePhone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'new_phone' => ['required', 'string', 'regex:/^05\d{8}$/'],
            'code'      => ['required', 'digits_between:4,8'],
        ], ['new_phone.regex' => 'رقم الجوال غير صحيح (يجب أن يبدأ بـ 05).']);

        $user = $request->user();
        $this->assertPhoneAvailable($data['new_phone'], $user);

        // Throws ValidationException on any OTP failure.
        $this->otp->verify($data['new_phone'], $data['code'], 'change-phone');

        $user->update(['phone' => PhoneNumber::toE164Ksa($data['new_phone'])]);

        return $this->success($user->fresh()->load('roles'), 'تم تحديث رقم الجوال بنجاح');
    }

    /**
     * §7.3 — soft account deletion. PII is scrubbed and the account deactivated,
     * but booking/payment records are preserved for financial/audit integrity.
     * Anonymising the phone frees it for future re-registration and blocks login.
     */
    public function deleteAccount(Request $request): Response
    {
        $user = $request->user();

        // Revoke every credential first so no in-flight token survives.
        $user->tokens()->delete();          // Sanctum access tokens
        $user->refreshTokens()->delete();   // rotating refresh tokens

        $user->forceFill([
            'name'      => null,
            'email'     => null,
            'phone'     => 'deleted-'.$user->id,   // unique placeholder, frees the real number
            'is_active' => false,
        ])->save();

        $user->syncRoles([]); // strip all access

        return response()->noContent();
    }

    /** Guard: a phone may only belong to one account. */
    private function assertPhoneAvailable(string $rawPhone, User $current): void
    {
        $e164 = PhoneNumber::toE164Ksa($rawPhone);

        $taken = User::where('phone', $e164)
            ->whereKeyNot($current->getKey())
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'new_phone' => ['رقم الجوال مستخدم بالفعل في حساب آخر.'],
            ]);
        }
    }
}
