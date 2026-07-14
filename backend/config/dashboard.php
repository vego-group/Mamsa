<?php

declare(strict_types=1);

/**
 * Partner-dashboard contract knobs.
 */
return [
    // §8.5 — host cancellations in the trailing 12 months before the partner
    // account is flagged for review. Business default suggested in the
    // contract (3/12mo, "confirm with Ahmed").
    'host_cancellation_flag_threshold' => (int) env('DASHBOARD_HOST_CANCEL_FLAG_THRESHOLD', 3),

    // Public site base for approved units' publicUrl.
    'public_site_url' => env('FRONTEND_URL', 'https://www.mamsaa.com'),

    // Max upload size for presigned files (bytes) — contract §9.1: 10MB.
    'upload_max_bytes' => 10 * 1024 * 1024,
];
