<?php

return [
    // Sanctum access-token lifetime (minutes). Applied to sanctum.expiration at boot.
    'access_minutes' => (int) env('AUTH_ACCESS_TOKEN_MINUTES', 60),

    // Custom refresh-token lifetime (days) per SRS.
    'refresh_days' => (int) env('AUTH_REFRESH_TOKEN_DAYS', 7),
];
