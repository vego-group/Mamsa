<?php

namespace App\Services;

use App\Services\Sms\SmsProvider;
use App\Support\PhoneNumber;
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
        $phone    = PhoneNumber::toE164Ksa($rawPhone);
        $cooldown = (int) config('otp.resend_seconds', 60);
        $ttl      = (int) config('otp.exp_minutes', 5) * 60;

        $existing = $this->get($phone, $purpose);

        if ($existing) {
            $elapsed = now()->timestamp - $existing['sent_at'];
            if ($elapsed < $cooldown) {
                $remain = $cooldown - $elapsed;
                throw ValidationException::withMessages([
                    'phone' => "الرجاء الانتظار {$remain} ثانية قبل إعادة الإرسال",
                ]);
            }
        }

        $this->enforceDailyCaps($phone, $ip);

        $code = $this->generateCode();

        $this->cache()->put(
            $this->key($phone, $purpose),
            ['code' => $code, 'attempts' => 0, 'sent_at' => now()->timestamp, 'ip' => $ip],
            $ttl
        );

        $this->sms->send($phone, $this->smsText($code, $purpose), config('sms.sender_id'));

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
            default        => 'لدخول منصة ممسى',
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
        $key   = $this->key($phone, $purpose);
        $otp   = $this->get($phone, $purpose);

        if (! $otp) {
            throw ValidationException::withMessages([
                'code' => ['رمز غير صحيح أو منتهي الصلاحية'],
            ]);
        }

        $maxAttempts = (int) config('otp.max_attempts', 3);

        if ($otp['attempts'] >= $maxAttempts) {
            $this->cache()->forget($key);
            throw ValidationException::withMessages([
                'code' => ['تم تجاوز الحد الأقصى للمحاولات. يرجى طلب رمز جديد.'],
            ]);
        }

        // Persist incremented attempt count before checking the code,
        // so brute-force attempts are counted even if the request is aborted.
        $otp['attempts']++;
        $this->cache()->put($key, $otp, (int) config('otp.exp_minutes', 5) * 60);

        if (! hash_equals((string) $otp['code'], trim($code))) {
            $remaining = $maxAttempts - $otp['attempts'];
            throw ValidationException::withMessages([
                'code' => ["رمز غير صحيح. المحاولات المتبقية: {$remaining}"],
            ]);
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

    private function generateCode(): string
    {
        // Deterministic code for non-production testing (staging/local), e.g. 111222.
        // Never honoured in production — live codes are always random.
        $fixed = config('otp.fixed_code');
        if ($fixed !== null && $fixed !== '' && ! app()->isProduction()) {
            return (string) $fixed;
        }

        $length = max(4, (int) config('otp.length', 6));
        $max    = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
