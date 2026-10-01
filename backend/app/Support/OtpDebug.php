<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether an API response may carry the one-time code it just issued.
 *
 * Only when the environment has decided to (OTP_DEBUG_RESPONSE=true), and
 * never on production whatever the flag says. A code in a response opens the
 * account to whoever made the request, so this is off unless chosen.
 */
final class OtpDebug
{
    public static function exposeCode(): bool
    {
        return config('otp.debug_response') === true && ! app()->isProduction();
    }
}
