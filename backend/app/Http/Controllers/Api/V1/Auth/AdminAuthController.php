<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminLoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\RefreshTokenService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Email + password authentication restricted to back-office roles
 * (Admin / SuperAdmin). Regular users and partners authenticate via OTP.
 */
class AdminAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RefreshTokenService $refreshTokens,
    ) {}

    public function login(AdminLoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var User|null $user */
        $user = User::where('email', $validated['email'])->first();

        // Single generic error for any failure — never leak which factor failed.
        $this->ensureValidCredentials($user, $validated['password']);

        // Gate strictly to back-office roles.
        if (! $user->isAdmin()) {
            $this->failAuth();
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'تم تعطيل هذا الحساب. تواصل مع مدير النظام.',
            ]);
        }

        $pair = $this->refreshTokens->issuePair($user, $validated['device'] ?? 'admin-web');

        return $this->success([
            'access_token'  => $pair['access_token'],
            'refresh_token' => $pair['refresh_token'],
            'token_type'    => 'Bearer',
            'expires_in'    => (int) config('tokens.access_minutes', 60) * 60,
            'user'          => new UserResource($pair['user']->load('roles')),
        ], 'تم تسجيل الدخول بنجاح');
    }

    /**
     * @phpstan-assert User $user
     */
    private function ensureValidCredentials(?User $user, string $password): void
    {
        // Hash::check on a dummy when user is missing keeps timing uniform.
        if (! $user || ! Hash::check($password, (string) $user->password)) {
            $this->failAuth();
        }
    }

    /**
     * @return never
     */
    private function failAuth(): void
    {
        throw ValidationException::withMessages([
            'email' => 'بيانات الدخول غير صحيحة.',
        ]);
    }
}
