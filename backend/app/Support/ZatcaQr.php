<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * ZATCA Phase 1 (MVP) invoice QR — a base64-encoded TLV string.
 *
 * The payload is five tags, in this order, per the ZATCA e-invoicing spec:
 *   1 seller name · 2 VAT registration number · 3 invoice timestamp (ISO-8601)
 *   4 invoice total INCLUDING VAT · 5 VAT total
 *
 * Each field is encoded as: tag (1 byte) + length in BYTES (1 byte) + value.
 * The length must count bytes, not characters — the seller name is Arabic, so
 * a character count would understate it and produce a code no reader accepts.
 *
 * Generated server-side by design (contract §7.1): the client renders the
 * returned string as an image and does nothing else with it.
 */
final class ZatcaQr
{
    /**
     * @return string|null base64 TLV, or null when it cannot be issued yet.
     */
    public static function forInvoice(
        string $sellerName,
        string $vatNumber,
        ?CarbonInterface $issuedAt,
        float $totalGross,
        float $totalVat,
    ): ?string {
        // A QR carrying a placeholder VAT number would look valid and fail at
        // the tax authority. Absent is safer than wrong, so emit nothing until
        // a real registration number is configured.
        if (trim($vatNumber) === '' || trim($sellerName) === '' || $issuedAt === null) {
            return null;
        }

        return base64_encode(
            self::tlv(1, $sellerName)
            .self::tlv(2, $vatNumber)
            .self::tlv(3, $issuedAt->toIso8601String())
            .self::tlv(4, number_format($totalGross, 2, '.', ''))
            .self::tlv(5, number_format($totalVat, 2, '.', ''))
        );
    }

    /** tag + byte-length + value. */
    private static function tlv(int $tag, string $value): string
    {
        return chr($tag).chr(strlen($value)).$value;
    }
}
