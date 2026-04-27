<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Services\Sms\SmsProvider;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public function __construct(private SmsProvider $sms) {}

    public function request(string $rawPhone, string $purpose = 'login', ?string $ip = null): void
    {
        $phone = $this->toE164Ksa($rawPhone);

        // ✅ احذف أي OTP قديم لنفس الرقم والغرض
        OtpCode::where('phone', $phone)
            ->where('purpose', $purpose)
            ->delete();

        // ✅ تحقق من وقت إعادة الإرسال (اختياري لكن موصى به)
        $existing = OtpCode::where('phone', $phone)
            ->where('purpose', $purpose)
            ->latest()
            ->first();

        $cooldown = (int) env('OTP_RESEND_SECONDS', 60);
        if ($existing && $existing->last_sent_at && $existing->last_sent_at->diffInSeconds(now()) < $cooldown) {
            $remain = $cooldown - $existing->last_sent_at->diffInSeconds(now());
            throw ValidationException::withMessages([
                'phone' => "الرجاء الانتظار {$remain} ثانية قبل إعادة الإرسال"
            ]);
        }

        // ✅ توليد كود 6 أرقام
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpCode::create([
            'phone'        => $phone,
            'code'         => $code,
            'expires_at'   => now()->addMinutes((int) env('OTP_EXP_MINUTES', 5)),
            'last_sent_at' => now(),
            'purpose'      => $purpose,
            'ip'           => $ip,
        ]);

        $msg = "رمز الدخول: {$code} صالح لمدة ".env('OTP_EXP_MINUTES', 5)." دقائق. لا تشاركه مع أحد.";
        $this->sms->send($phone, $msg, config('sms.sender_id'));
    }


    public function verify(string $rawPhone, string $code, string $purpose = 'login'): bool
    {
        $phone = $this->toE164Ksa($rawPhone);

        $otp = OtpCode::where('phone', $phone)
            ->where('purpose', $purpose)
            ->where('code', trim($code))
            ->latest()
            ->first();

        if (!$otp || $otp->expires_at->isPast()) {
            return false;
        }

        $otp->increment('attempts');
        $otp->delete();

        return true;
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
