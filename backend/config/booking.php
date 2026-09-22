<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Booking price breakdown (ملخص السعر)
    |--------------------------------------------------------------------------
    | Rates used to itemise a booking total at creation time. The computed
    | line items are FROZEN onto the booking row, so changing these values
    | later never re-prices an existing booking.
    |
    | Owner decision 2026-07-18 (final): no cleaning fee, no service fee —
    | the guest pays subtotal + VAT only. Historical bookings keep their
    | frozen fee values; see App\Support\Pricing.
    */

    // KSA VAT, applied to the nightly subtotal. Legal rate — deliberately
    // config-only, no admin edit surface.
    'tax_rate' => (float) env('BOOKING_TAX_RATE', 0.15),

    /*
     * Mamsa's commission on partner rentals — a share of the nightly subtotal
     * (the NET base), never of the VAT.
     *
     * Deducted from the partner's payout. It does NOT change what the guest
     * pays: raising it moves money from the partner to the platform, it does
     * not raise the price of a night.
     *
     * Applies to bookings made from now on. Every booking freezes the rate and
     * the amount it was created under, so existing bookings keep the deal they
     * were made on — see App\Support\Pricing and the frozen columns
     * `commission_rate` / `commission_amount`. Reconstructing commission for
     * rows that predate freezing uses Booking::LEGACY_COMMISSION_RATE, which is
     * deliberately a different number.
     *
     * Raised from 2% to 10% on 2026-08-27 (owner).
     */
    'commission_rate' => (float) env('BOOKING_COMMISSION_RATE', 0.10),

    // Email task doc §2 — POST /bookings refuses guests without a verified
    // email (EMAIL_VERIFICATION_REQUIRED). Env-flagged per environment: ON
    // for the Next.js user site (staging), OFF on prod until the live
    // frontend ships the verification screen — flipping it earlier would
    // block every existing phone-only guest from booking.
    'require_verified_email' => (bool) env('BOOKING_REQUIRE_VERIFIED_EMAIL', false),
];
