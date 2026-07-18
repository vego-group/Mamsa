<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single source of truth for the guest-facing price breakdown (ملخص السعر).
 * Used by both the availability preview and booking creation so what the
 * checkout page shows is byte-identical to what gets frozen and charged.
 *
 * Owner decision 2026-07-18 (final): NO cleaning fee, NO service fee.
 *   subtotal = nightly × nights
 *   tax      = subtotal × 15% (KSA VAT)
 *   total    = subtotal + tax
 *
 * Both lines are rounded to 2 decimals BEFORE summing, so total × 100 is an
 * exact integer — payments derive amount_halalas with zero rounding drift.
 * Historical bookings that charged fees keep their frozen values; only the
 * serializers know about them (rendered when non-zero so old invoices add up).
 */
final class Pricing
{
    /** Legal VAT rate — config-only, no runtime edit surface by design. */
    public static function taxPercent(): float
    {
        return round((float) config('booking.tax_rate') * 100, 2);
    }

    /**
     * @return array{nights: int, nightly_rate: float, subtotal: float,
     *   taxes: float, tax_percent: float, commission_rate: float,
     *   commission_amount: float, total: float}
     */
    public static function breakdown(float $nightly, int $nights): array
    {
        $taxPercent = self::taxPercent();
        $subtotal   = round($nightly * $nights, 2);
        $taxes      = round($subtotal * $taxPercent / 100, 2);

        // Mamsa's cut of the partner's rental income — deducted from the
        // partner's payout, so it is NOT part of the guest-facing total.
        $commissionRate = (float) config('booking.commission_rate');

        return [
            'nights'            => $nights,
            'nightly_rate'      => $nightly,
            'subtotal'          => $subtotal,
            'taxes'             => $taxes,
            'tax_percent'       => $taxPercent,
            'commission_rate'   => $commissionRate,
            'commission_amount' => round($subtotal * $commissionRate, 2),
            'total'             => round($subtotal + $taxes, 2),
        ];
    }
}
