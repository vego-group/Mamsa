<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * OTP failure carrying a machine-readable code. Extends ValidationException so
 * every existing consumer (user-site auth, partner register, phone change)
 * keeps its 422 behaviour; the partner-dashboard layer maps the code to its
 * own envelope (OTP_WRONG / OTP_EXPIRED / OTP_LOCKED).
 */
class OtpException extends ValidationException
{
    public string $otpCode = 'OTP_WRONG';

    public function setOtpCode(string $code): static
    {
        $this->otpCode = $code;

        return $this;
    }
}
