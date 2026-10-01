<?php

namespace App\Services;

use App\Exceptions\OtpException;
use App\Services\Sms\SmsProvider;
use App\Support\PhoneNumber;
use App\Support\TestMode;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public function __construct(private SmsProvider $sms) {}

    /** Cache store holding OTP codes — configurable so non-Redis envs work. */
    private function cache(): CacheRepository
    {
        return Cache::store(config('otp.store'));
    }

    public function request(string $rawPhone, string $purpose = 'login', ?string $ip = null): string
    {
        $phone = PhoneNumber::toE164Ksa($rawPhone);
        $cooldown = (int) config('otp.resend_seconds', 60);
        $ttl = (int) config('otp.exp_minutes', 5) * 60;

        // Scoped test account: return the fixed code, skip the SMS, and bypass the
        // resend cooldown + daily caps so the demo flow is never throttled. Gated
        // to the allowlist by TestMode, so a real user can never reach this branch.
        $bypass = TestMode::otpBypass($phone);

        $existing = $this->get($phone, $purpose);

        if (! $bypass && $existing) {
            $elapsed = now()->timestamp - $existing['sent_at'];
            if ($elapsed < $cooldown) {
                $remain = $cooldown - $elapsed;
                throw ValidationException::withMessages([
                    'phone' => "الرجاء الانتظار {$remain} ثانية قبل إعادة الإرسال",
                ]);
            }
        }

        if (! $bypass) {
            $this->enforceDailyCaps($phone, $ip);
        }

        $code = $bypass ? (string) TestMode::code() : $this->generateCode();

        $this->cache()->put(
            $this->key($phone, $purpose),
            ['code' => $code, 'attempts' => 0, 'sent_at' => now()->timestamp, 'ip' => $ip],
            $ttl
        );

        if (! $bypass) {
            $this->sms->send($phone, $this->smsText($code, $purpose), config('sms.sender_id'));
        }

        return $code;
    }

    /**
     * CST-compliant OTP text: must state the purpose of the message and the
     * platform name alongside the code (per the Taqnyat-approved templates,
     * e.g. "رمز التحقق:XXXX لدخول منصة taqnyat.sa").
     */
    private function smsText(string $code, string $purpose): string
    {
        $expMinutes = (int) config('otp.exp_minutes', 5);

        $reason = match ($purpose) {
            'change-phone' => 'لتغيير رقم الجوال في منصة ممسى',
            default => 'لدخول منصة ممسى',
        };

        return "رمز التحقق: {$code} {$reason}. صالح لمدة {$expMinutes} دقائق، لا تشاركه مع أحد.";
    }

    /**
     * Verify an OTP code. Throws ValidationException on every failure —
     * callers never need to check a return value.
     */
    public function verify(string $rawPhone, string $code, string $purpose = 'login'): void
    {
        $phone = PhoneNumber::toE164Ksa($rawPhone);
        $key = $this->key($phone, $purpose);
        $otp = $this->get($phone, $purpose);

        if (! $otp) {
            throw OtpException::withMessages([
                'code' => ['رمز غير صحيح أو منتهي الصلاحية'],
            ])->setOtpCode('OTP_EXPIRED');
        }

        $maxAttempts = (int) config('otp.max_attempts', 3);

        if ($otp['attempts'] >= $maxAttempts) {
            $this->cache()->forget($key);
            throw OtpException::withMessages([
                'code' => ['تم تجاوز الحد الأقصى للمحاولات. يرجى طلب رمز جديد.'],
            ])->setOtpCode('OTP_LOCKED');
        }

        // Persist incremented attempt count before checking the code,
        // so brute-force attempts are counted even if the request is aborted.
        $otp['attempts']++;
        $this->cache()->put($key, $otp, (int) config('otp.exp_minutes', 5) * 60);

        if (! hash_equals((string) $otp['code'], trim($code))) {
            $remaining = $maxAttempts - $otp['attempts'];
            throw OtpException::withMessages([
                'code' => ["رمز غير صحيح. المحاولات المتبقية: {$remaining}"],
            ])->setOtpCode('OTP_WRONG');
        }

        $this->cache()->forget($key);
    }

    private function get(string $phone, string $purpose): ?array
    {
        return $this->cache()->get($this->key($phone, $purpose));
    }

    private function key(string $phone, string $purpose): string
    {
        return "otp:{$purpose}:{$phone}";
    }

    /**
     * Cap OTP sends per phone and per IP per calendar day to blunt SMS-pumping
     * fraud. Counters auto-expire at midnight. A breach throws before any SMS
     * is sent (and before the cooldown counter is touched).
     */
    private function enforceDailyCaps(string $phone, ?string $ip): void
    {
        $day = now()->format('Ymd');

        $checks = [
            ['otp:cap:phone:'.$phone.':'.$day, (int) config('otp.max_per_phone_per_day', 10)],
            ['otp:cap:ip:'.($ip ?? 'unknown').':'.$day, (int) config('otp.max_per_ip_per_day', 30)],
        ];

        foreach ($checks as [$cacheKey, $max]) {
            if ($max <= 0) {
                continue; // 0 = disabled
            }

            if ((int) $this->cache()->get($cacheKey, 0) >= $max) {
                throw ValidationException::withMessages([
                    'phone' => ['تم تجاوز الحد المسموح من المحاولات اليوم. حاول غداً.'],
                ]);
            }
        }

        // Increment only after both limits pass, so a blocked request is not counted.
        foreach ($checks as [$cacheKey, $max]) {
            if ($max > 0) {
                $this->cache()->put($cacheKey, (int) $this->cache()->get($cacheKey, 0) + 1, now()->endOfDay());
            }
        }
    }

    /**
     * Always random. There is no fixed-code path here any more.
     *
     * There used to be: OTP_FIXED_CODE made EVERY code for EVERY account equal
     * to one constant whenever APP_ENV was not "production". Two things made
     * that worse than it looked. It was not scoped to a phone, so it unlocked
     * any account on the environment rather than a test account. And its only
     * defence was a denylist on one environment variable — this project has
     * already run production with APP_DEBUG=true for five days, so "APP_ENV is
     * definitely right" is not a control.
     *
     * The scoped mechanism it duplicated is TestMode::otpBypass(), which
     * requires a master switch AND a configured code AND the phone to be on an
     * explicit allowlist. That one can stay: it cannot unlock anything but a
     * listed test number, wherever it runs.
     *
     * Removing this costs developers nothing, which is why it could go rather
     * than merely being narrowed: `debug_otp` already returns the real code on
     * non-production, and staging runs SMS_DRIVER=log.
     */
    private function generateCode(): string
    {
        $length = max(4, (int) config('otp.length', 6));
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
