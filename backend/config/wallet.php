<?php

declare(strict_types=1);

/**
 * Partner wallet / payout knobs — wallet contract §1.
 */
return [
    /*
     * The transfer threshold, in SAR. Server-owned: the client only renders
     * "you need X more", so moving it must not require a frontend release.
     *
     * A partner below this is not paid in the monthly run; the balance carries
     * forward untouched.
     */
    'min_payout_amount' => (float) env('WALLET_MIN_PAYOUT', 2000),

    'currency' => 'SAR',
];
