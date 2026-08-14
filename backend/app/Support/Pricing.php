<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single source of truth for the guest-facing price breakdown (ملخص السعر).
 * Used by both the availability preview and booking creation so what the
 * checkout page shows is byte-identical to what gets frozen and charged.
 *
 * ─── VAT-INCLUSIVE PRICING (contract v2.2 §1, effective 2026-08-14) ───
 *
 * `units.price` is the GROSS, VAT-INCLUSIVE nightly price. The guest sees the
 * final payable figure everywhere and VAT is broken out for transparency, never
 * added on top. In Saudi Arabia a consumer price must be displayed VAT-inclusive
 * (§1.2), so the previous model — net price plus 15% at checkout — was not
 * merely a display choice.
 *
 *   gross        = round2(nightly × nights)          ← what the guest pays
 *   netBase      = round2(gross / (1 + VAT_RATE))
 *   vat          = round2(gross − netBase)           ← by SUBTRACTION
 *   commission   = round2(netBase × COMMISSION_RATE) ← on the NET base, never on VAT
 *   partnerShare = round2(netBase − commission)      ← by SUBTRACTION
 *
 * Both invariants hold exactly under rounding because `vat` and `partnerShare`
 * are derived by subtraction rather than by an independent multiplication:
 *   netBase + vat                  === gross
 *   commission + partnerShare + vat === gross
 *
 * Commission is charged on `netBase`, not on `gross`: VAT is collected on behalf
 * of the tax authority and passed through, so taking a platform cut of it would
 * overstate commission by 15% and is not defensible in an audit (§1.4).
 *
 * Legacy key aliases (`subtotal`, `taxes`, `total`) are retained because the
 * live Vue app and the frozen booking columns already carry those names and
 * they map exactly onto the new concepts: subtotal IS the net base, taxes IS
 * the VAT, total IS the gross. Nothing is renamed in the database.
 *
 * Every line is rounded to 2 decimals, so total × 100 is an exact integer and
 * payments derive amount_halalas with zero drift.
 */
final class Pricing
{
    /** Legal VAT rate — config-only, no runtime edit surface by design. */
    public static function taxPercent(): float
    {
        return round(self::vatRate() * 100, 2);
    }

    /** VAT rate as a fraction, e.g. 0.15. */
    public static function vatRate(): float
    {
        return (float) config('booking.tax_rate');
    }

    /**
     * @param  float  $nightlyGross  GROSS (VAT-inclusive) price per night
     * @return array{nights:int, nightly_rate:float, gross:float, net_base:float,
     *   vat:float, vat_rate:float, subtotal:float, taxes:float, tax_percent:float,
     *   commission_rate:float, commission_amount:float, partner_share:float, total:float}
     */
    public static function breakdown(float $nightlyGross, int $nights): array
    {
        $vatRate = self::vatRate();

        $gross   = round($nightlyGross * $nights, 2);
        $netBase = round($gross / (1 + $vatRate), 2);
        $vat     = round($gross - $netBase, 2);          // subtraction keeps the invariant

        // Mamsa's cut of the partner's NET rental income — deducted from the
        // partner's payout, so it is NOT part of the guest-facing total.
        $commissionRate = (float) config('booking.commission_rate');
        $commission     = round($netBase * $commissionRate, 2);
        $partnerShare   = round($netBase - $commission, 2); // subtraction again

        return [
            'nights'            => $nights,
            'nightly_rate'      => $nightlyGross,

            // Contract §1.7 names.
            'gross'             => $gross,
            'net_base'          => $netBase,
            'vat'               => $vat,
            'vat_rate'          => $vatRate,

            // Legacy aliases — same numbers, names the DB columns and the live
            // Vue app already use. subtotal === net_base, taxes === vat.
            'subtotal'          => $netBase,
            'taxes'             => $vat,
            'tax_percent'       => self::taxPercent(),
            'total'             => $gross,

            // Internal settlement — never exposed on a guest surface (§1.7, §7).
            'commission_rate'   => $commissionRate,
            'commission_amount' => $commission,
            'partner_share'     => $partnerShare,
        ];
    }
}
