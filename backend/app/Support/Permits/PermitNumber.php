<?php

declare(strict_types=1);

namespace App\Support\Permits;

/**
 * One spelling for a permit number.
 *
 * `50047139`, `٥٠٠٤٧١٣٩` and ` 50047139 ` are the same permit and three
 * different strings. A uniqueness rule that compares strings would let the
 * second and third through, and a reviewer searching for the first would not
 * find them. So every number is normalised on the way in — before it is
 * stored, and before it is compared with anything — and only ever exists in
 * this one form.
 *
 * What changes: leading/trailing space is dropped; Arabic-Indic (٠–٩) and
 * Extended Arabic-Indic (۰–۹) digits become ASCII; every whitespace character
 * and both commas (`,` `،`) are removed; ASCII letters are upper-cased. What
 * does NOT change: hyphens, slashes and other punctuation — `TL-DEMO-8UNITS`
 * is a number with hyphens in it, not two numbers.
 */
final class PermitNumber
{
    private const ARABIC_INDIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const EXTENDED_ARABIC_INDIC = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const ASCII = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = str_replace(self::ARABIC_INDIC, self::ASCII, $raw);
        $value = str_replace(self::EXTENDED_ARABIC_INDIC, self::ASCII, $value);
        // \s misses NBSP and the Arabic-script spaces without the u flag.
        $value = preg_replace('/[\s\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF},،]+/u', '', $value) ?? $value;
        $value = strtoupper($value);

        return $value === '' ? null : $value;
    }
}
