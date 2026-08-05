<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Central gate for the scoped test accounts (fixed OTP + simulated payments).
 *
 * Everything is keyed off an explicit phone allowlist and two master switches
 * (config/test_mode.php), so enabling it in production only ever affects the
 * listed demo numbers — never a real user.
 */
class TestMode
{
    /** Normalised E.164 allowlist of test phones. */
    public static function phones(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($p) => self::normalise((string) $p),
            (array) config('test_mode.phones', []),
        ))));
    }

    /** True when the given raw phone is one of the allowlisted test numbers. */
    public static function isTestPhone(?string $rawPhone): bool
    {
        $e164 = self::normalise((string) $rawPhone);

        return $e164 !== null && in_array($e164, self::phones(), true);
    }

    /** The fixed OTP code, or null when unset (which disables the OTP bypass). */
    public static function code(): ?string
    {
        $code = (string) config('test_mode.code', '');

        return $code === '' ? null : $code;
    }

    /**
     * Should OTP be faked for this phone? True only when the master switch is on,
     * a fixed code is configured, AND the phone is allowlisted. When true, the
     * caller must skip the real SMS send and store the fixed code instead.
     */
    public static function otpBypass(?string $rawPhone): bool
    {
        return (bool) config('test_mode.otp')
            && self::code() !== null
            && self::isTestPhone($rawPhone);
    }

    /**
     * Should the payment be simulated (no Moyasar) for this phone? True only when
     * the payments switch is on AND the phone is allowlisted.
     */
    public static function paymentBypass(?string $rawPhone): bool
    {
        return (bool) config('test_mode.payments')
            && self::isTestPhone($rawPhone);
    }

    /** Best-effort E.164 normalisation that never throws. */
    private static function normalise(string $raw): ?string
    {
        if (trim($raw) === '') {
            return null;
        }

        try {
            return PhoneNumber::toE164Ksa($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
