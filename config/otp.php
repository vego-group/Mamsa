<?php

return [
    'exp_minutes'    => (int) env('OTP_EXP_MINUTES', 5),
    'resend_seconds' => (int) env('OTP_RESEND_SECONDS', 60),
    'max_attempts'   => (int) env('OTP_MAX_ATTEMPTS', 5),
];
