<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Email-verification failures for the user-site API. Carries the machine code
 * the Next.js app branches on (email task doc §1) and renders the unified
 * `{ success:false, message, code }` envelope itself — controllers don't catch.
 */
class EmailVerificationException extends Exception
{
    /** @param array<string, mixed> $meta extra top-level keys (e.g. retry_after) */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }

    public static function invalidEmail(): self
    {
        return new self('EMAIL_INVALID', 'البريد الإلكتروني غير صالح.');
    }

    public static function alreadyInUse(): self
    {
        return new self('EMAIL_ALREADY_IN_USE', 'هذا البريد الإلكتروني مستخدم في حساب آخر.');
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(
            'RATE_LIMITED',
            "الرجاء الانتظار {$retryAfter} ثانية قبل إعادة الإرسال.",
            429,
            ['retry_after' => $retryAfter],
        );
    }

    public static function otpInvalid(int $remainingAttempts): self
    {
        return new self(
            'OTP_INVALID',
            "رمز غير صحيح. المحاولات المتبقية: {$remainingAttempts}",
            422,
            ['remaining_attempts' => $remainingAttempts],
        );
    }

    public static function otpExpired(): self
    {
        return new self('OTP_EXPIRED', 'انتهت صلاحية الرمز. يرجى طلب رمز جديد.');
    }

    public static function otpMaxAttempts(): self
    {
        return new self('OTP_MAX_ATTEMPTS', 'تم تجاوز الحد الأقصى للمحاولات. يرجى طلب رمز جديد.');
    }

    public static function noPendingEmail(): self
    {
        return new self('EMAIL_INVALID', 'لا يوجد بريد إلكتروني بانتظار التحقق. أضف بريدك أولاً.');
    }

    public function render(): JsonResponse
    {
        return response()->json(array_merge([
            'success' => false,
            'message' => $this->getMessage(),
            'code'    => $this->errorCode,
        ], $this->meta), $this->status);
    }
}
