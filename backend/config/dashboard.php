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

    /*
     * Minimum acceptable photo resolution, expressed as long/short edge rather
     * than width/height so the rule does not silently reject every portrait —
     * a phone held upright is a normal way to photograph a room.
     *
     * Set to admit the images already in the library while still refusing
     * something unusable in a full-screen viewer. Raise it once real partner
     * uploads show what the floor should be; it is deliberately env-tunable so
     * that does not need a deploy.
     */
    'image_min_long_edge'  => (int) env('IMAGE_MIN_LONG_EDGE', 1024),
    'image_min_short_edge' => (int) env('IMAGE_MIN_SHORT_EDGE', 576),

    // WebP quality for generated derivatives.
    'image_quality' => (int) env('IMAGE_QUALITY', 82),

    /*
     * The canonical stored file is re-encoded for one reason — to strip EXIF,
     * which carries the property's GPS position into a public bucket. Kept
     * higher than the derivatives since it is a re-encode of the partner's
     * own upload, not a resize.
     */
    'image_original_quality' => (int) env('IMAGE_ORIGINAL_QUALITY', 90),

    /*
     * Is the identity scan mandatory for an individual partner registration?
     *
     * Making it required is a breaking change to a live client contract: the
     * form must send multipart with `national_id_file` or signup 422s. The flag
     * exists so the server side can ship everywhere first and the requirement
     * be switched on per environment once each frontend has shipped its form —
     * rather than forking the validation rules per environment.
     *
     * The file is ALWAYS validated (type/size) when supplied; this only governs
     * whether omitting it is allowed.
     */
    'require_identity_file' => filter_var(env('PARTNER_REQUIRE_IDENTITY_FILE', true), FILTER_VALIDATE_BOOL),

    /*
     * Whether a COMPANY must supply its commercial-registration scan at
     * registration. Deliberately separate from require_identity_file: turning
     * it on before the registration form can send `cr_file` would 422 every
     * company signup. Flip it once the client ships the field.
     */
    'require_cr_file' => (bool) env('DASHBOARD_REQUIRE_CR_FILE', false),
];
