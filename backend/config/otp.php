<?php

return [
    'length'         => (int) env('OTP_LENGTH', 6),
    'exp_minutes'    => (int) env('OTP_EXP_MINUTES', 5),

    // Fixed OTP for NON-PRODUCTION testing (e.g. 111222). When set and APP_ENV
    // is not "production", every OTP becomes this value — so debug_otp / the SMS
    // log always shows it. Ignored entirely in production (codes stay random).
    'fixed_code'     => env('OTP_FIXED_CODE'),
    'resend_seconds' => (int) env('OTP_RESEND_SECONDS', 60),
    'max_attempts'   => (int) env('OTP_MAX_ATTEMPTS', 3),

    // Cache store used to hold OTP codes. Defaults to the app's default
    // cache store (CACHE_STORE). On environments without Redis (e.g. shared
    // hosting) set OTP_STORE=file or database; on Docker keep it as redis.
    'store'          => env('OTP_STORE', env('CACHE_STORE', 'file')),

    // Anti-fraud daily send caps (SMS pumping protection). Counted per calendar
    // day; 0 disables the check.
    'max_per_phone_per_day' => (int) env('OTP_MAX_PER_PHONE_PER_DAY', 10),
    'max_per_ip_per_day'    => (int) env('OTP_MAX_PER_IP_PER_DAY', 30),
];
