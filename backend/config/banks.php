<?php

declare(strict_types=1);

/**
 * SAMA bank codes → bank name, read from IBAN characters 5–6.
 *
 * ⚠️ VERIFY WITH FINANCE BEFORE TREATING AS AUTHORITATIVE. Only `80` (Al Rajhi)
 * is confirmed against a known-good IBAN. The rest are the commonly published
 * mapping and have NOT been checked against SAMA's own register.
 *
 * An unknown code resolves to null, which the client renders as a neutral
 * state — so the failure mode of a missing entry is harmless. The failure mode
 * of a WRONG entry is not: a partner would read the wrong bank against their
 * payout account. Delete an entry rather than guess at it.
 *
 * Correcting this map is a config edit, not a deploy.
 */
return [
    'sama_codes' => [
        '05' => 'مصرف الإنماء',
        '10' => 'البنك الأهلي السعودي',
        '15' => 'البنك السعودي الفرنسي',
        '20' => 'بنك الرياض',
        '30' => 'البنك العربي الوطني',
        '40' => 'البنك السعودي البريطاني',
        '45' => 'البنك السعودي للاستثمار',
        '55' => 'بنك الجزيرة',
        '60' => 'بنك البلاد',
        '80' => 'مصرف الراجحي',   // confirmed
    ],
];
