<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\User;

use App\Exceptions\EmailVerificationException;
use App\Http\Controllers\Controller;
use App\Services\EmailVerificationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Email task doc §1 — verified email as a trusted contact channel (login stays
 * phone-OTP only). All error paths return the machine codes the Next.js app
 * branches on: EMAIL_INVALID / EMAIL_ALREADY_IN_USE / RATE_LIMITED /
 * OTP_INVALID / OTP_EXPIRED / OTP_MAX_ATTEMPTS.
 */
class EmailController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly EmailVerificationService $emails) {}

    /** POST /user/email — attach or change the email, unverified + OTP sent. */
    public function store(Request $request): JsonResponse
    {
        $user  = $request->user();
        $email = strtolower(trim((string) $request->input('email')));

        // Two separate checks so each failure maps to its own machine code.
        if (Validator::make(['email' => $email], [
            'email' => ['required', 'string', 'email:rfc', 'max:150'],
        ])->fails()) {
            throw EmailVerificationException::invalidEmail();
        }

        // users.email carries a DB unique index — one account per email.
        if (Validator::make(['email' => $email], [
            'email' => [Rule::unique('users', 'email')->ignore($user->id)],
        ])->fails()) {
            throw EmailVerificationException::alreadyInUse();
        }

        // Re-submitting the exact same unverified email = a resend request.
        if ($email === strtolower((string) $user->email) && ! $user->email_verified_at) {
            $this->emails->resendPending($user);
        } else {
            $this->emails->start($user, $email);
        }

        return $this->success([
            'email'               => $email,
            'verified'            => false,
            'resend_available_in' => (int) config('otp.resend_seconds', 60),
        ], 'تم إرسال رمز التحقق إلى بريدك الإلكتروني');
    }

    /** POST /user/email/verify — 6-digit code, 5 attempts, then the code dies. */
    public function verifyCode(Request $request): JsonResponse
    {
        $code = trim((string) $request->input('code'));

        // Malformed input is OTP_INVALID too (no attempt consumed) — the
        // frontend branches on one code for "wrong code" either way.
        if (! preg_match('/^\d{6}$/', $code)) {
            throw new EmailVerificationException('OTP_INVALID', 'الرمز يجب أن يكون 6 أرقام.');
        }

        $user = $request->user();

        $this->emails->confirm($user, $code);

        return $this->success([
            'email'    => $user->email,
            'verified' => true,
        ], 'تم التحقق من البريد الإلكتروني بنجاح');
    }

    /** POST /user/email/resend — 60s cooldown (RATE_LIMITED + retry_after). */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->emails->resendPending($user);

        return $this->success([
            'email'               => $user->email,
            'verified'            => false,
            'resend_available_in' => (int) config('otp.resend_seconds', 60),
        ], 'تم إعادة إرسال رمز التحقق');
    }
}
