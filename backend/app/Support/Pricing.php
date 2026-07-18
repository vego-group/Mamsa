<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Single source of truth for the guest-facing price breakdown (ملخص السعر).
 * Used by both the availability preview and booking creation so what the
 * checkout page shows is byte-identical to what gets frozen and charged.
 *
 * Formula (frontend contract 2026-07-18):
 *   subtotal   = nightly × nights
 *   serviceFee = subtotal × serviceFeePercent/100      (platform_settings, superadmin-editable)
 *   tax        = (subtotal + cleaningFee + serviceFee) × taxPercent/100   (VAT on the full invoice)
 *   total      = subtotal + cleaningFee + serviceFee + tax
 *
 * Every line is rounded to 2 decimals BEFORE summing, so total × 100 is an
 * exact integer — payments derive amount_halalas with zero rounding drift.
 */
final class Pricing
{
    private const CACHE_KEY = 'platform_settings.service_fee_percent';

    /** Superadmin-editable; DB value with the config rate (×100) as fallback. */
    public static function serviceFeePercent(): float
    {
        return (float) Cache::rememberForever(
            self::CACHE_KEY,
            fn () => PlatformSetting::find('service_fee_percent')?->value
                ?? config('booking.service_fee_rate') * 100,
        );
    }

    /** Legal VAT rate — config-only, no runtime edit surface by design. */
    public static function taxPercent(): float
    {
        return round((float) config('booking.tax_rate') * 100, 2);
    }

    public static function setServiceFeePercent(float $percent): void
    {
        PlatformSetting::updateOrCreate(
            ['key' => 'service_fee_percent'],
            ['value' => (string) $percent],
        );

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{nights: int, nightly_rate: float, subtotal: float,
     *   service_fee: float, service_fee_percent: float, cleaning_fee: float,
     *   taxes: float, tax_percent: float, commission_rate: float,
     *   commission_amount: float, total: float}
     */
    public static function breakdown(float $nightly, int $nights, float $cleaningFee): array
    {
        $serviceFeePercent = self::serviceFeePercent();
        $taxPercent        = self::taxPercent();

        $subtotal    = round($nightly * $nights, 2);
        $serviceFee  = round($subtotal * $serviceFeePercent / 100, 2);
        $cleaningFee = round($cleaningFee, 2);
        $taxes       = round(($subtotal + $cleaningFee + $serviceFee) * $taxPercent / 100, 2);

        // Mamsa's cut of the partner's rental income — deducted from the
        // partner's payout, so it is NOT part of the guest-facing total.
        $commissionRate = (float) config('booking.commission_rate');

        return [
            'nights'              => $nights,
            'nightly_rate'        => $nightly,
            'subtotal'            => $subtotal,
            'service_fee'         => $serviceFee,
            'service_fee_percent' => $serviceFeePercent,
            'cleaning_fee'        => $cleaningFee,
            'taxes'               => $taxes,
            'tax_percent'         => $taxPercent,
            'commission_rate'     => $commissionRate,
            'commission_amount'   => round($subtotal * $commissionRate, 2),
            'total'               => round($subtotal + $serviceFee + $cleaningFee + $taxes, 2),
        ];
    }
}
