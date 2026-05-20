<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Services\Sms\SmsProvider;
use Illuminate\Validation\ValidationException;

class OtpService
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private SmsProvider $sms) {}

    public function request(string $rawPhone, string $purpose = 'login', ?string $ip = null): void
    {
        $phone    = $this->toE164Ksa($rawPhone);
        $cooldown = (int) config('otp.resend_seconds', 60);

        $existing = OtpCode::where('phone', $phone)
            ->where('purpose', $purpose)
            ->latest()
            ->first();

        if ($existing && $existing->last_sent_at && $existing->last_sent_at->diffInSeconds(now()) < $cooldown) {
            $remain = $cooldown - $existing->last_sent_at->diffInSeconds(now());
            throw ValidationException::withMessages([
                'phone' => "الرجاء الانتظار {$remain} ثانية قبل إعادة الإرسال",
            ]);
        }

        // delete any previous OTPs for this phone+purpose before creating new one
        OtpCode::where('phone', $phone)->where('purpose', $purpose)->delete();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $code=123456;
        OtpCode::create([
            'phone'        => $phone,
            'code'         => $code,
            'expires_at'   => now()->addMinutes((int) config('otp.exp_minutes', 5)),
            'last_sent_at' => now(),
            'purpose'      => $purpose,
            'ip'           => $ip,
        ]);

        $expMinutes = config('otp.exp_minutes', 5);
        $msg = "رمز الدخول: {$code} صالح لمدة {$expMinutes} دقائق. لا تشاركه مع أحد.";
        $this->sms->send($phone, $msg, config('sms.sender_id'));
    }

    public function verify(string $rawPhone, string $code, string $purpose = 'login'): bool
    {
        $phone = $this->toE164Ksa($rawPhone);
        $otp   = OtpCode::where('phone', $phone)->where('purpose', $purpose)->latest()->first();

        if (! $otp || $otp->expires_at->isPast()) {
            return false;
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            $otp->delete();
            throw ValidationException::withMessages([
                'code' => 'تم تجاوز الحد الأقصى للمحاولات. يرجى طلب رمز جديد.',
            ]);
        }

        $otp->increment('attempts');

        if (hash_equals((string) $otp->code, trim($code))) {
            $otp->delete();
            return true;
        }

        return false;
    }

    private function toE164Ksa(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw);

        if (str_starts_with($d, '966'))   return '+'.$d;
        if (str_starts_with($d, '05'))    return '+966'.substr($d, 1);
        if (str_starts_with($d, '5'))     return '+966'.$d;
        if (str_starts_with($d, '00966')) return '+'.substr($d, 2);

        return '+'.$d;
    }
}
