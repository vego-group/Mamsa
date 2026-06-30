<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PartnerRegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\EmailVerificationService;
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
        private readonly EmailVerificationService $emails,
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

        // FR-005 — fire off the email verification code. Best-effort: a mail
        // hiccup must never fail the registration itself.
        $needsEmailVerification = $user->email && ! $user->email_verified_at;
        if ($needsEmailVerification) {
            try {
                $this->emails->send($user);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $this->success([
            'access_token'             => $pair['access_token'],
            'refresh_token'            => $pair['refresh_token'],
            'token_type'               => 'Bearer',
            'expires_in'               => (int) config('tokens.access_minutes', 60) * 60,
            'needs_email_verification' => (bool) $needsEmailVerification,
            'user'                     => new UserResource($pair['user']->load('roles')),
        ], 'تم تسجيلك كشريك بنجاح', 201);
    }
}
