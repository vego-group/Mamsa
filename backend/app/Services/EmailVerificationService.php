<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\EmailVerificationCode;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * FR-005 / FR-006 — partner email verification. Mirrors the SMS OTP flow
 * (cache-backed, single-use, attempt-limited) but delivers a code by email.
 * Reuses the otp.* config so policy (length, expiry, attempts, resend) stays
 * in one place.
 */
class EmailVerificationService
{
    /** Same store as the phone OTP — configurable for non-Redis environments. */
    private function cache(): CacheRepository
    {
        return Cache::store(config('otp.store'));
    }

    /**
     * Generate + email a fresh verification code for the user's email address.
     * Enforces a resend cooldown and refuses if there is no email or it is
     * already verified.
     */
    public function send(User $user): void
    {
        if (blank($user->email)) {
            throw ValidationException::withMessages([
                'email' => ['لا يوجد بريد إلكتروني مرتبط بالحساب.'],
            ]);
        }

        if ($user->email_verified_at) {
            throw ValidationException::withMessages([
                'email' => ['تم التحقق من البريد الإلكتروني مسبقاً.'],
            ]);
        }

        $key      = $this->key($user);
        $cooldown = (int) config('otp.resend_seconds', 60);
        $existing = $this->cache()->get($key);

        if ($existing) {
            $elapsed = now()->timestamp - $existing['sent_at'];
            if ($elapsed < $cooldown) {
                $remain = $cooldown - $elapsed;
                throw ValidationException::withMessages([
                    'email' => ["الرجاء الانتظار {$remain} ثانية قبل إعادة الإرسال"],
                ]);
            }
        }

        $code       = $this->generateCode();
        $expMinutes = (int) config('otp.exp_minutes', 5);

        $this->cache()->put(
            $key,
            ['code' => $code, 'attempts' => 0, 'sent_at' => now()->timestamp, 'email' => $user->email],
            $expMinutes * 60,
        );

        Mail::to($user->email)->send(new EmailVerificationCode($code, $expMinutes));
    }

    /**
     * Verify a submitted code and mark the email verified. Throws on every
     * failure path (expired, wrong, too many attempts) — callers don't branch.
     */
    public function verify(User $user, string $code): void
    {
        $key  = $this->key($user);
        $data = $this->cache()->get($key);

        if (! $data) {
            throw ValidationException::withMessages([
                'code' => ['رمز غير صحيح أو منتهي الصلاحية'],
            ]);
        }

        $maxAttempts = (int) config('otp.max_attempts', 3);

        if ($data['attempts'] >= $maxAttempts) {
            $this->cache()->forget($key);
            throw ValidationException::withMessages([
                'code' => ['تم تجاوز الحد الأقصى للمحاولات. يرجى طلب رمز جديد.'],
            ]);
        }

        // Count the attempt before comparing so aborted requests still count.
        $data['attempts']++;
        $this->cache()->put($key, $data, (int) config('otp.exp_minutes', 5) * 60);

        if (! hash_equals((string) $data['code'], trim($code))) {
            $remaining = $maxAttempts - $data['attempts'];
            throw ValidationException::withMessages([
                'code' => ["رمز غير صحيح. المحاولات المتبقية: {$remaining}"],
            ]);
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->cache()->forget($key);
    }

    private function key(User $user): string
    {
        return "email-verify:{$user->id}";
    }

    private function generateCode(): string
    {
        $length = max(4, (int) config('otp.length', 6));
        $max    = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
