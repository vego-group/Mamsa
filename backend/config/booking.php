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
    | Cleaning fee is per-unit (units.cleaning_fee, partner-editable) since
    | 2026-07-18 — there is no platform-wide cleaning fee anymore.
    */

    // Fallback service fee as a fraction of the nightly subtotal. The live
    // value is the platform_settings row (superadmin-editable, in percent);
    // this only applies when that row is missing. See App\Support\Pricing.
    'service_fee_rate' => (float) env('BOOKING_SERVICE_FEE_RATE', 0.10),

    // KSA VAT, applied to the full invoice (subtotal + cleaning + service).
    // Legal rate — deliberately config-only, no admin edit surface.
    'tax_rate' => (float) env('BOOKING_TAX_RATE', 0.15),

    // Mamsa's commission on partner rentals (2% of the nightly subtotal,
    // never on fees or taxes). Deducted from the partner's earnings —
    // it does not change what the guest pays.
    'commission_rate' => (float) env('BOOKING_COMMISSION_RATE', 0.02),
];
