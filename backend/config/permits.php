<?php

declare(strict_types=1);

return [
    /*
     * Whether a listing must carry a permit expiry date to be submitted for
     * review.
     *
     * Off until an admin has filled in the dates for the listings that already
     * exist. It has a reach that is easy to miss: an approved listing goes back
     * to `pending` whenever it is edited, so with this on, ANY edit to an old
     * listing — a price change — would be refused until its permit date is
     * entered. That is the right end state and the wrong first day.
     */
    'expiry_required' => (bool) env('PERMIT_EXPIRY_REQUIRED', false),

    /*
     * How close to expiry a permit starts reading as "expiring" on the
     * consoles, and the first reminder threshold (phase 3).
     */
    'warning_days' => (int) env('PERMIT_WARNING_DAYS', 30),

    /*
     * Permit numbers allowed to sit on more than one listing.
     *
     * The rule is one number, one scope. Production breaks it once: `50047139`
     * is attached to unit #35 (published) and unit #37 (hidden), because the
     * same private permit was used for two listings before anything checked.
     * Refusing it would block every future edit and renewal on both, and
     * quietly skipping duplicates would hide the next one — so it is named
     * here, and only here.
     *
     * Remove the number when the owner decides which listing keeps it. That is
     * an env change, not a deploy.
     */
    'uniqueness_exceptions' => array_filter(array_map(
        'trim',
        explode(',', (string) env('PERMIT_UNIQUENESS_EXCEPTIONS', '50047139')),
    )),
];
