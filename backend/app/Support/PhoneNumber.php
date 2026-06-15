<?php

namespace App\Support;

class PhoneNumber
{
    /**
     * Normalise a raw Saudi phone input to E.164 (+9665XXXXXXXX).
     */
    public static function toE164Ksa(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw);

        if (str_starts_with($d, '00966')) return '+'.substr($d, 2);
        if (str_starts_with($d, '966'))   return '+'.$d;
        if (str_starts_with($d, '05'))    return '+966'.substr($d, 1);
        if (str_starts_with($d, '5'))     return '+966'.$d;

        return '+'.$d;
    }
}
