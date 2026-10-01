<?php

return [
    'length' => (int) env('OTP_LENGTH', 6),
    'exp_minutes' => (int) env('OTP_EXP_MINUTES', 5),

    // OTP_FIXED_CODE is GONE, deliberately, and must not come back.
    //
    // It made every code for every account equal to one constant whenever
    // APP_ENV was not "production" — a back door to any account on the
    // environment, not a test account, defended only by a denylist on a single
    // env var. The scoped equivalent is test_mode.code, which additionally
    // requires the phone to be on an explicit allowlist.
    //
    // Developers do not need it: debug_otp returns the real code on
    // non-production and staging logs SMS instead of sending it.
    'resend_seconds' => (int) env('OTP_RESEND_SECONDS', 60),
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 3),

    // Cache store used to hold OTP codes. Defaults to the app's default
    // cache store (CACHE_STORE). On environments without Redis (e.g. shared
    // hosting) set OTP_STORE=file or database; on Docker keep it as redis.
    'store' => env('OTP_STORE', env('CACHE_STORE', 'file')),

    // Anti-fraud daily send caps (SMS pumping protection). Counted per calendar
    // day; 0 disables the check.
    'max_per_phone_per_day' => (int) env('OTP_MAX_PER_PHONE_PER_DAY', 10),
    'max_per_ip_per_day' => (int) env('OTP_MAX_PER_IP_PER_DAY', 30),
];
