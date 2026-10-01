<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Saudi IBAN handling — wallet contract §6.
 *
 * A Saudi IBAN is `SA` + 2 check digits + 2-digit SAMA bank code + 18 digits.
 */
final class Iban
{
    /** Uppercase, all whitespace and separators stripped. */
    public static function normalize(?string $iban): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $iban) ?? '');
    }

    /**
     * Shape + ISO 7064 mod-97 checksum.
     *
     * The checksum matters more than the shape: a mistyped digit keeps the
     * shape and fails the checksum, and that is the case that would otherwise
     * send a transfer into the void.
     */
    public static function isValid(?string $iban): bool
    {
        $iban = self::normalize($iban);

        if (! preg_match('/^SA\d{22}$/', $iban)) {
            return false;
        }

        // Move the first four characters to the end, then letters → numbers
        // (A = 10 … Z = 35), and the whole thing mod 97 must equal 1.
        $rearranged = substr($iban, 4).substr($iban, 0, 4);

        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        // bcmod-free: fold in chunks so the value never exceeds PHP int range.
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ((string) $remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }

    /** `••••` + the last four digits. Never return a full IBAN to a client. */
    public static function mask(?string $iban): string
    {
        $iban = self::normalize($iban);

        return '••••'.substr($iban, -4);
    }

    /**
     * Bank name from the SAMA bank code (IBAN characters 5–6).
     *
     * Returns null for an unrecognised code — a neutral state is correct, a
     * WRONG bank name is not: a partner reading the wrong bank on their payout
     * account has every reason to think the money is going somewhere else.
     */
    public static function bankName(?string $iban): ?string
    {
        $iban = self::normalize($iban);

        if (! preg_match('/^SA\d{22}$/', $iban)) {
            return null;
        }

        return config('banks.sama_codes.'.substr($iban, 4, 2));
    }
}
