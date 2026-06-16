<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PartnerRegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\OtpService;
use App\Services\RefreshTokenService;
use App\Support\PhoneNumber;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Self-service partner onboarding. The applicant proves phone ownership via the
 * standard OTP (request-otp), then submits their partner profile here. We attach
 * the Individual/Company role and partner_detail, and issue an auth token pair.
 */
class PartnerAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OtpService $otp,
        private readonly RefreshTokenService $refreshTokens,
    ) {}

    public function register(PartnerRegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Throws ValidationException on any OTP failure (same purpose as login).
        $this->otp->verify($data['phone'], $data['code'], 'login');

        $phone = PhoneNumber::toE164Ksa($data['phone']);
        $role  = $data['type'] === 'company' ? 'Company' : 'Individual';

        $user = DB::transaction(function () use ($data, $phone, $role) {
            $user = User::firstOrCreate(['phone' => $phone], ['is_active' => true]);

            // Don't let an admin account be silently downgraded to a partner.
            if ($user->isAdmin()) {
                throw ValidationException::withMessages([
                    'phone' => 'هذا الرقم مسجَّل كحساب إداري ولا يمكن تحويله إلى شريك.',
                ]);
            }

            $user->update([
                'name'      => $data['name'],
                'email'     => $data['email'] ?? $user->email,
                'is_active' => true,
            ]);

            // Partner roles are exclusive of the base User role.
            $user->syncRoles($role);

            $user->partnerDetail()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'type'        => $data['type'],
                    'national_id' => $data['type'] === 'individual' ? $data['national_id'] : null,
                    'cr_number'   => $data['type'] === 'company' ? $data['cr_number'] : null,
                ],
            );

            return $user;
        });

        $pair = $this->refreshTokens->issuePair($user, $data['device'] ?? 'partner-web');

        return $this->success([
            'access_token'  => $pair['access_token'],
            'refresh_token' => $pair['refresh_token'],
            'token_type'    => 'Bearer',
            'expires_in'    => (int) config('tokens.access_minutes', 60) * 60,
            'user'          => new UserResource($pair['user']->load('roles')),
        ], 'تم تسجيلك كشريك بنجاح', 201);
    }
}
