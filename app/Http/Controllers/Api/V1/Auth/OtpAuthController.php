<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\OtpService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OtpAuthController extends Controller
{
    use ApiResponse;

    public function __construct(private OtpService $otp) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone'  => ['required', 'string', 'min:8', 'max:20'],
            'intent' => ['nullable', 'in:login,Admin'],
        ]);

        $this->otp->request(
            $request->phone,
            'login',
            $request->ip()
        );

        return $this->success(
            ['phone' => $request->phone],
            'تم إرسال رمز التحقق'
        );
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone'  => ['required', 'string', 'min:8', 'max:20'],
            'code'   => ['required', 'digits_between:4,8'],
            'intent' => ['nullable', 'in:login,Admin'],
            'device' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $this->otp->verify($validated['phone'], $validated['code'], 'login')) {
            throw ValidationException::withMessages([
                'code' => 'رمز غير صحيح أو منتهي الصلاحية',
            ]);
        }

        $user = User::firstOrCreate(
            ['phone' => $validated['phone']],
            ['is_active' => 1]
        );

        $this->assignDefaultRole($user, $validated['intent'] ?? 'login');

        $deviceName  = $validated['device'] ?? 'mobile';
        $token       = $user->createToken($deviceName)->plainTextToken;
        $needsProfile = blank($user->name);

        return $this->success([
            'token'         => $token,
            'token_type'    => 'Bearer',
            'needs_profile' => $needsProfile,
            'user'          => new UserResource($user->load('roles')),
        ], $needsProfile ? 'يرجى إكمال بيانات الملف الشخصي' : 'تم تسجيل الدخول بنجاح');
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'min:8', 'max:20'],
        ]);

        $this->otp->request($request->phone, 'login', $request->ip());

        return $this->success(null, 'تم إعادة إرسال الرمز');
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
            'تم تحديث الملف الشخصي'
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'تم تسجيل الخروج');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success(
            new UserResource($request->user()->load('roles'))
        );
    }

    private function assignDefaultRole(User $user, string $intent): void
    {
        if ($user->roles()->exists()) {
            return;
        }

        $roleName = $intent === 'Admin' ? 'Admin' : 'User';
        $user->assignRole($roleName, true);

        // fallback direct insert
        if (! DB::table('user_roles')->where('user_id', $user->id)->exists()) {
            $role = Role::where('name', $roleName)->first();
            if ($role) {
                DB::table('user_roles')->insert([
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                ]);
            }
        }
    }
}
