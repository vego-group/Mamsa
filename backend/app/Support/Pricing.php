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
     * Split ANY gross, VAT-inclusive amount at a GIVEN commission rate.
     *
     * The one place the arithmetic above is implemented. Both callers go
     * through it, which is what makes "the refund uses the same logic as the
     * booking" a property of the code rather than a promise in a document:
     *
     *   - {@see breakdown()} splits a booking at the rate live *right now*
     *   - {@see \App\Models\Booking::splitRefund()} splits a refund at the
     *     rate frozen on that booking
     *
     * The rate is a PARAMETER, never read from config here. A refund on a
     * booking taken at 2% must return 2% commission even though the live rate
     * is 10%; reading config would restate history and debit the partner the
     * wrong amount. See Booking::LEGACY_COMMISSION_RATE.
     *
     * @param  float  $gross  GROSS, VAT-inclusive amount
     * @param  float  $commissionRate  fraction, e.g. 0.10 — or 1.0 for a
     *                                 Mamsa-owned unit, where there is no
     *                                 partner and the platform keeps the net
     * @return array{gross:float, net_base:float, vat:float, vat_rate:float,
     *   commission_rate:float, commission_amount:float, partner_share:float}
     */
    public static function split(float $gross, float $commissionRate): array
    {
        $vatRate = self::vatRate();

        $gross   = round($gross, 2);
        $netBase = round($gross / (1 + $vatRate), 2);
        $vat     = round($gross - $netBase, 2);          // subtraction keeps the invariant

        // At a rate of 1.0 the platform keeps the whole net base. Taking it
        // as-is rather than round($netBase * 1.0, 2) keeps partnerShare exactly
        // 0.00 instead of a rounding crumb that would post a ledger entry for
        // fractions of a halala.
        $commission   = $commissionRate >= 1.0 ? $netBase : round($netBase * $commissionRate, 2);
        $partnerShare = round($netBase - $commission, 2); // subtraction again

        return [
            'gross'             => $gross,
            'net_base'          => $netBase,
            'vat'               => $vat,
            'vat_rate'          => $vatRate,
            'commission_rate'   => $commissionRate,
            'commission_amount' => $commission,
            'partner_share'     => $partnerShare,
        ];
    }

    /**
     * @param  float  $nightlyGross  GROSS (VAT-inclusive) price per night
     * @param  bool  $mamsaOwned  the unit belongs to the platform, not a partner
     * @return array{nights:int, nightly_rate:float, gross:float, net_base:float,
     *   vat:float, vat_rate:float, subtotal:float, taxes:float, tax_percent:float,
     *   commission_rate:float, commission_amount:float, partner_share:float, total:float}
     */
    public static function breakdown(float $nightlyGross, int $nights, bool $mamsaOwned = false): array
    {
        // Mamsa's cut of the partner's NET rental income — deducted from the
        // partner's payout, so it is NOT part of the guest-facing total.
        //
        // On a MAMSA-OWNED unit there is no partner to pay. `units.user_id` on
        // such a listing is the admin who created it, so splitting 2%/98% here
        // would accrue 98% of every booking into that admin's partner wallet
        // and queue it for a real bank transfer — money owed to nobody. The
        // platform keeps the whole net base instead, and the invariant
        // commission + partnerShare + vat === gross still holds exactly.
        $commissionRate = $mamsaOwned ? 1.0 : (float) config('booking.commission_rate');

        $split = self::split(round($nightlyGross * $nights, 2), $commissionRate);

        $gross   = $split['gross'];
        $netBase = $split['net_base'];
        $vat     = $split['vat'];
        $vatRate = $split['vat_rate'];

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
            'commission_rate'   => $split['commission_rate'],
            'commission_amount' => $split['commission_amount'],
            'partner_share'     => $split['partner_share'],
        ];
    }
}
