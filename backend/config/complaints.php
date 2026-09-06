<?php

declare(strict_types=1);

return [
    /*
     * Who hears about a refund that failed or is stuck.
     *
     * A comma-separated list of email addresses, read from the environment so
     * adding the second recipient is a value change rather than a release
     * (v1.2 §2). When it is empty the alert falls back to every active
     * SuperAdmin, which is what the ledger consistency check already does —
     * so an unset variable degrades to today's behaviour rather than to
     * silence, and an alert is never simply lost because nobody set a key.
     */
    'alert_recipients' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('COMPLAINTS_ALERT_RECIPIENTS', ''))
    ))),

    /*
     * A gateway-accepted refund settles by webhook. If that webhook never
     * arrives the refund sits `pending` forever: the guest is waiting, the
     * partner has not been debited, and the complaint is half-closed.
     *
     * `reconcile_after_hours` is when the scheduled job starts asking Moyasar
     * what actually happened; `alert_after_hours` is when a human is told.
     */
    'reconcile_after_hours' => (int) env('COMPLAINTS_RECONCILE_AFTER_HOURS', 6),
    'alert_after_hours'     => (int) env('COMPLAINTS_ALERT_AFTER_HOURS', 24),

    /*
     * When the metadata.env stamp went live.
     *
     * A payment created BEFORE this has no stamp, and a missing stamp on one of
     * those is not a mismatch — refusing them would strand refunds on live
     * bookings. A payment created AFTER it must carry one, and a missing stamp
     * there is a real anomaly.
     *
     * Without this constant, "stamp absent" stays acceptable forever and the
     * guard quietly decays into nothing as the old payments age out.
     */
    'env_stamp_live_from' => env('COMPLAINTS_ENV_STAMP_FROM', '2026-09-06T00:00:00+00:00'),

    /* Guest-facing limits (spec §4.2 / §5.1). */
    'description_min' => 20,
    'description_max' => 2000,

    /*
     * The complaint window: from check-in until 48h after check-out, in Riyadh
     * time. Stored as hours so a product change is a value, not a code edit.
     */
    'window_hours_after_checkout' => (int) env('COMPLAINTS_WINDOW_HOURS', 48),

    /*
     * Assumed check-out time when a unit has none recorded.
     *
     * 12:00 because that is what 26 of 32 units actually carry, and what the
     * documentation states. The alternative — treating a missing time as
     * midnight — silently shortens the guest's window to 36 hours on exactly
     * the units where the platform is missing data. Our gap, their deadline.
     */
    'default_checkout_time' => env('COMPLAINTS_DEFAULT_CHECKOUT_TIME', '12:00'),
];
