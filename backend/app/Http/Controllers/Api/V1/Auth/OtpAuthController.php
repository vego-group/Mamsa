<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\OtpService;
use App\Services\RefreshTokenService;
use App\Support\PhoneNumber;
use App\Traits\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OtpAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OtpService $otp,
        private RefreshTokenService $refreshTokens,
    ) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone'  => ['required', 'string', 'min:8', 'max:20'],
            'intent' => ['nullable', 'in:login,register'],
        ]);

        $this->guardIntent($request->phone, $request->input('intent'));

        $code = $this->otp->request($request->phone, 'login', $request->ip());

        $data = ['phone' => $request->phone];

        // Expose OTP in dev so developers don't have to tail logs
        if (! app()->isProduction()) {
            $data['debug_otp'] = $code;
        }

        return $this->success($data, 'تم إرسال رمز التحقق');
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone'  => ['required', 'string', 'min:8', 'max:20'],
            'intent' => ['nullable', 'in:login,register'],
        ]);

        $this->guardIntent($request->phone, $request->input('intent'));

        $code = $this->otp->request($request->phone, 'login', $request->ip());

        $data = [];
        if (! app()->isProduction()) {
            $data['debug_otp'] = $code;
        }

        return $this->success($data ?: null, 'تم إعادة إرسال الرمز');
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone'  => ['required', 'string', 'min:8', 'max:20'],
            'code'   => ['required', 'digits_between:4,8'],
            'device' => ['nullable', 'string', 'max:255'],
        ]);

        // throws ValidationException on any failure — no return value to check
        $this->otp->verify($validated['phone'], $validated['code'], 'login');

        $user = User::firstOrCreate(
            ['phone' => PhoneNumber::toE164Ksa($validated['phone'])],
            ['is_active' => true],
        );

        if (! $user->roles()->exists()) {
            $user->assignRole('User');
        }

        $pair = $this->refreshTokens->issuePair($user, $validated['device'] ?? 'mobile');
        $needsProfile = blank($user->name);

        return $this->success(
            $this->tokenPayload($pair, ['needs_profile' => $needsProfile]),
            $needsProfile ? 'يرجى إكمال بيانات الملف الشخصي' : 'تم تسجيل الدخول بنجاح',
        );
    }

    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refresh_token' => ['required', 'string'],
            'device'        => ['nullable', 'string', 'max:255'],
        ]);

        $pair = $this->refreshTokens->rotate($validated['refresh_token'], $validated['device'] ?? 'mobile');

        if (! $pair) {
            return $this->error('رمز التحديث غير صالح أو منتهي الصلاحية', 401);
        }

        return $this->success($this->tokenPayload($pair), 'تم تحديث الجلسة');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success(
            new UserResource($request->user()->load('roles')),
        );
    }

    public function completeProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'unique:users,email,'.$user->id],
        ]);

        $user->update($validated);

        return $this->success(
            new UserResource($user->load('roles')),
            'تم تحديث الملف الشخصي',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        $this->refreshTokens->revokeForAccessToken($token->getKey());
        $token->delete();

        return $this->success(null, 'تم تسجيل الخروج');
    }

    /**
     * Explicit login/register separation (backend gaps #A). Fails fast — before
     * any SMS is sent or user row created — with a machine-readable `code` the
     * frontend can branch on. A row whose profile was never completed (blank
     * name) counts as unregistered, so an abandoned sign-in can still register.
     * Omitting `intent` keeps the legacy unified passwordless behaviour.
     */
    private function guardIntent(string $rawPhone, ?string $intent): void
    {
        if ($intent === null) {
            return;
        }

        $registered = User::where('phone', PhoneNumber::toE164Ksa($rawPhone))
            ->whereNotNull('name')
            ->exists();

        if ($intent === 'login' && ! $registered) {
            $this->failIntent('هذا الرقم غير مسجّل', 'PHONE_NOT_REGISTERED');
        }

        if ($intent === 'register' && $registered) {
            $this->failIntent('هذا الرقم مسجّل بالفعل، يرجى تسجيل الدخول', 'PHONE_ALREADY_REGISTERED');
        }
    }

    private function failIntent(string $message, string $code): never
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $message,
            'code'    => $code,
        ], 422));
    }

    /**
     * @param  array{user: User, access_token: string, refresh_token: string}  $pair
     */
    private function tokenPayload(array $pair, array $extra = []): array
    {
        return array_merge([
            'access_token'  => $pair['access_token'],
            'refresh_token' => $pair['refresh_token'],
            'token_type'    => 'Bearer',
            'expires_in'    => (int) config('tokens.access_minutes', 60) * 60,
            'user'          => new UserResource($pair['user']->load('roles')),
        ], $extra);
    }
}
