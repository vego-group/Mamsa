<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test-mode accounts (fixed OTP + simulated payments)
|--------------------------------------------------------------------------
| SAFE to leave enabled in production: everything here is scoped to an
| explicit phone allowlist. Only the listed numbers ever get the fixed OTP
| (no SMS) or a simulated payment — every real user still receives a random
| SMS OTP and is charged live on Moyasar.
|
| Two independent master switches turn it off instantly:
|   TEST_OTP_MODE=false       → all accounts back to real SMS OTP
|   TEST_PAYMENTS_MODE=false  → all accounts back to live Moyasar charges
|
| The fixed code has NO default on purpose: with TEST_OTP_CODE unset the OTP
| bypass stays inert even if the switch is on, so the public repo never
| discloses a working credential. Set a PRIVATE 6-digit value in .env.
*/

$accounts = [
    'user' => env('TEST_USER_PHONE', '+966555000001'),
    'partner' => env('TEST_PARTNER_PHONE', '+966555000002'),
    'superadmin' => env('TEST_SUPERADMIN_PHONE', '+966555000003'),
];

return [
    // Master switches (both default OFF — must be explicitly enabled).
    'otp' => (bool) env('TEST_OTP_MODE', false),
    'payments' => (bool) env('TEST_PAYMENTS_MODE', false),

    // Fixed OTP handed to the allowlisted phones. Must be 6 digits to satisfy
    // the admin/partner "digits:6" rule. Empty = bypass disabled (see above).
    'code' => (string) env('TEST_OTP_CODE', ''),

    // The three canonical demo accounts, by role. `test-accounts:sync` seeds
    // exactly these numbers with the right role/profile.
    'accounts' => $accounts,

    // OTP/payment allowlist = the three accounts above + any ad-hoc extras
    // (comma-separated). Normalised to E.164 by App\Support\TestMode.
    'phones' => array_values(array_filter(array_merge(
        array_values($accounts),
        array_map('trim', explode(',', (string) env('TEST_PHONES', ''))),
    ))),
];
