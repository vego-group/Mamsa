<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Booking price breakdown (ملخص السعر)
    |--------------------------------------------------------------------------
    | Rates used to itemise a booking total at creation time. The computed
    | line items are FROZEN onto the booking row, so changing these values
    | later never re-prices an existing booking. Defaults mirror the design
    | (subtotal 6000 → service 600 / cleaning 300 / tax 420 → total 7320).
    */

    // Service fee as a fraction of the nightly subtotal (10%).
    'service_fee_rate' => (float) env('BOOKING_SERVICE_FEE_RATE', 0.10),

    // Flat cleaning fee in SAR, charged once per booking.
    'cleaning_fee' => (float) env('BOOKING_CLEANING_FEE', 300),

    // Tax as a fraction of the nightly subtotal (7%).
    'tax_rate' => (float) env('BOOKING_TAX_RATE', 0.07),

    // Mamsa's commission on partner rentals (2% of the nightly subtotal,
    // never on fees or taxes). Deducted from the partner's earnings —
    // it does not change what the guest pays.
    'commission_rate' => (float) env('BOOKING_COMMISSION_RATE', 0.02),
];
