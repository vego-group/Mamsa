<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\EmailVerificationException;
use App\Mail\EmailVerificationCode;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * FR-005 / FR-006 — email verification by 6-digit code. Mirrors the SMS OTP
 * flow (cache-backed, single-use, attempt-limited) but delivers by email.
 * Reuses the otp.* config so policy (length, expiry, resend) stays in one
 * place; attempts use otp.email_max_attempts (5 — email task doc §1).
 *
 * Two entry points share the same stored code:
 *  - partner flow (/auth/email/*): send()/verify(), ValidationException errors
 *  - user flow (/user/email*): start()/confirm(), EmailVerificationException
 *    carrying the machine codes the Next.js app branches on.
 */
class EmailVerificationService
{
    /** Same store as the phone OTP — configurable for non-Redis environments. */
    private function cache(): CacheRepository
    {
        return Cache::store(config('otp.store'));
    }

    /* -----------------------------------------------------------------
     |  User-site flow (email task doc §1) — machine-coded errors
     | ----------------------------------------------------------------- */

    /**
     * Attach (or change) the account email: store it unverified and send a
     * fresh code. A changed email always drops back to verified=false.
     *
     * @throws EmailVerificationException RATE_LIMITED
     */
    public function start(User $user, string $email): void
    {
        $this->assertCooldownPassed($user);

        // Changing the address invalidates any previous verification.
        $user->forceFill(['email' => $email, 'email_verified_at' => null])->save();

        $this->issue($user);
    }

    /**
     * Re-send the code for the pending (unverified) email.
     *
     * @throws EmailVerificationException RATE_LIMITED / EMAIL_INVALID
     */
    public function resendPending(User $user): void
    {
        if (blank($user->email) || $user->email_verified_at) {
            throw EmailVerificationException::noPendingEmail();
        }

        $this->assertCooldownPassed($user);
        $this->issue($user);
    }

    /**
     * Check a submitted code and mark the email verified.
     *
     * @throws EmailVerificationException OTP_INVALID / OTP_EXPIRED / OTP_MAX_ATTEMPTS
     */
    public function confirm(User $user, string $code): void
    {
        if (blank($user->email) || $user->email_verified_at) {
            throw EmailVerificationException::noPendingEmail();
        }

        $key  = $this->key($user);
        $data = $this->cache()->get($key);

        if (! $data) {
            // Nothing stored (never requested, or long past the grace window).
            throw EmailVerificationException::otpExpired();
        }

        if (now()->timestamp > $data['expires_at']) {
            $this->cache()->forget($key);
            throw EmailVerificationException::otpExpired();
        }

        $max = $this->maxAttempts();

        // Count the attempt before comparing so aborted requests still count.
        $data['attempts']++;
        $this->cache()->put($key, $data, $this->graceTtl());

        if (! hash_equals((string) $data['code'], trim($code))) {
            if ($data['attempts'] >= $max) {
                // 5 wrong tries kill the code entirely (doc §1) — request a new one.
                $this->cache()->forget($key);
                throw EmailVerificationException::otpMaxAttempts();
            }

            throw EmailVerificationException::otpInvalid($max - $data['attempts']);
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->cache()->forget($key);
    }

    /** Seconds until a resend is allowed (0 = allowed now). */
    public function resendAvailableIn(User $user): int
    {
        $existing = $this->cache()->get($this->key($user));

        if (! $existing) {
            return 0;
        }

        $elapsed = now()->timestamp - $existing['sent_at'];

        return max(0, $this->cooldown() - $elapsed);
    }

    /* -----------------------------------------------------------------
     |  Partner flow (/auth/email/*) — original ValidationException API
     | ----------------------------------------------------------------- */

    /**
     * Generate + email a fresh verification code for the user's existing email.
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

        try {
            $this->assertCooldownPassed($user);
        } catch (EmailVerificationException $e) {
            throw ValidationException::withMessages(['email' => [$e->getMessage()]]);
        }

        $this->issue($user);
    }

    /**
     * Verify a submitted code and mark the email verified. Throws on every
     * failure path (expired, wrong, too many attempts) — callers don't branch.
     */
    public function verify(User $user, string $code): void
    {
        try {
            $this->confirm($user, $code);
        } catch (EmailVerificationException $e) {
            $field = in_array($e->errorCode, ['EMAIL_INVALID'], true) ? 'email' : 'code';
            throw ValidationException::withMessages([$field => [$e->getMessage()]]);
        }
    }

    /* -----------------------------------------------------------------
     |  Internals
     | ----------------------------------------------------------------- */

    /** @throws EmailVerificationException */
    private function assertCooldownPassed(User $user): void
    {
        $remain = $this->resendAvailableIn($user);

        if ($remain > 0) {
            throw EmailVerificationException::rateLimited($remain);
        }
    }

    /** Store a fresh code and email it to the user's current address. */
    private function issue(User $user): void
    {
        $code       = $this->generateCode();
        $expMinutes = (int) config('otp.exp_minutes', 5);

        $this->cache()->put(
            $this->key($user),
            [
                'code'       => $code,
                'attempts'   => 0,
                'sent_at'    => now()->timestamp,
                // Real expiry lives in the payload; the cache TTL adds a grace
                // window so an expired code reports OTP_EXPIRED, not OTP_INVALID.
                'expires_at' => now()->timestamp + ($expMinutes * 60),
                'email'      => $user->email,
            ],
            $this->graceTtl(),
        );

        Mail::to($user->email)->send(new EmailVerificationCode($code, $expMinutes));
    }

    private function key(User $user): string
    {
        return "email-verify:{$user->id}";
    }

    private function cooldown(): int
    {
        return (int) config('otp.resend_seconds', 60);
    }

    private function maxAttempts(): int
    {
        return (int) config('otp.email_max_attempts', 5);
    }

    /** Cache lifetime: code validity + 30 min so expiry is distinguishable. */
    private function graceTtl(): int
    {
        return ((int) config('otp.exp_minutes', 5) * 60) + 1800;
    }

    private function generateCode(): string
    {
        // Deterministic code for non-production testing (staging fixed OTP),
        // mirroring OtpService — NEVER active when APP_ENV=production.
        $fixed = config('otp.fixed_code');
        if ($fixed !== null && $fixed !== '' && ! app()->isProduction()) {
            return (string) $fixed;
        }

        $length = max(4, (int) config('otp.length', 6));
        $max    = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
