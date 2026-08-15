<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tax invoice (ZATCA Phase 1)
|--------------------------------------------------------------------------
| Mamsa is the supplier of record (contract §1.5), so the tax invoice is
| issued in Mamsa's name and these are the seller details printed on it and
| encoded into the QR.
|
| They live in config — NOT in the frontend — so that a change to the
| company's registration details updates every future invoice without a
| frontend deploy.
|
| ⚠️ The QR cannot be generated until `vat_number` is a real ZATCA-issued
| number: the whole purpose of the code is that a reader validates these
| exact values. While it is blank the invoice endpoint returns
| `qrCode: null`, which the client renders as "preparing" rather than as a
| broken or fake code.
*/

return [
    'seller' => [
        'name'      => env('INVOICE_SELLER_NAME', 'منصة ممسى'),
        // 15 digits, starts and ends with 3. Blank until issued → qrCode stays null.
        'vat_number' => env('INVOICE_SELLER_VAT_NUMBER', ''),
        // Commercial registration, 10 digits.
        'cr_number' => env('INVOICE_SELLER_CR_NUMBER', ''),
        'address'   => env('INVOICE_SELLER_ADDRESS', ''),
    ],

    // Invoice numbers are derived deterministically from the booking, so the
    // same booking always yields the same number and no sequence table is
    // needed. Format: INV-{YYYY}-{MM}-{booking id, 6 digits}.
    'number_prefix' => env('INVOICE_NUMBER_PREFIX', 'INV'),

    'currency' => env('INVOICE_CURRENCY', 'SAR'),
];
